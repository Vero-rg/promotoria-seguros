<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Policy;
use App\Models\Scheme;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class PolicyImportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = [
        'Número de póliza',
        'Cliente',
        'Producto',
        'Agente',
        'Fecha de emisión',
        'PNA',
        '% Costo facturación',
        '% Impuesto cedular',
        'Estatus',
        'Conducto de pago',
    ];

    public function test_import_creates_policies_with_commissions_based_on_pca(): void
    {
        Agent::factory()->create(['name' => 'Andrea López', 'is_active' => true]);
        $this->createCommissionScheme();

        $file = $this->makeSpreadsheet([
            ['111111', 'Juan Pérez', 'METALIFE', 'Andrea López', '15/07/2026', 90000, 5, 3, 'Pagada', 'Automático'],
            ['222222', '', 'METALIFE', 'andrea lopez', '2026-07-16', 50000, 4, null, '', null],
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->post(route('policies.import'), ['file' => $file]);

        $response->assertRedirect();
        $response->assertSessionHas('import_result', function ($result) {
            return $result['imported'] === 2 && $result['errors'] === [];
        });

        $this->assertDatabaseCount('policies', 2);

        $policy = Policy::where('policy_number', '111111')->firstOrFail();

        // PCA = 90,000 − 1,350 = 88,650 → agente 56% y promotor 14%
        $this->assertEquals('49644.00', $policy->commission_amount);
        $this->assertEquals('12411.00', $policy->promoter_commission_amount);
        $this->assertEquals('5.00', $policy->billing_retention);
        $this->assertEquals('10.00', $policy->isr_retention);
        $this->assertEquals('3.00', $policy->cedular_tax);
        $this->assertEquals('Pagada', $policy->status);
        $this->assertEquals('2026-07-15', $policy->issue_date->toDateString());
        $this->assertEquals('METALIFE', $policy->product_type);
        $this->assertEquals('Automático', $policy->payment_method);

        $secondPolicy = Policy::where('policy_number', '222222')->firstOrFail();

        $this->assertEquals('Activa', $secondPolicy->status);
        $this->assertEquals('10.00', $secondPolicy->isr_retention);
        // Sin impuesto cedular en el archivo → se guarda en 0
        $this->assertEquals('0.00', $secondPolicy->cedular_tax);
        $this->assertEquals('Efectivo', $secondPolicy->payment_method);
        $this->assertNull($secondPolicy->client_name);
    }

    public function test_import_defaults_cedular_tax_to_five_for_agent_with_flag_enabled(): void
    {
        Agent::factory()->create([
            'name' => 'Andrea López',
            'is_active' => true,
            'cedular_tax_enabled' => true,
        ]);
        $this->createCommissionScheme();

        $file = $this->makeSpreadsheet([
            ['777777', 'Cliente', 'METALIFE', 'Andrea López', '15/07/2026', 90000, 5, null, 'Activa', 'Efectivo'],
        ]);

        $this->actingAs(User::factory()->create())
            ->post(route('policies.import'), ['file' => $file]);

        $this->assertEquals('5.00', Policy::where('policy_number', '777777')->firstOrFail()->cedular_tax);
    }

    public function test_import_reports_rows_with_errors_and_skips_them(): void
    {
        Agent::factory()->create(['name' => 'Andrea', 'is_active' => true]);
        $this->createCommissionScheme();

        $file = $this->makeSpreadsheet([
            ['333333', 'Cliente', 'METALIFE', 'Agente inexistente', '15/07/2026', 90000, 5, null, null, null],
            ['444444', 'Cliente', 'PRODUCTO INEXISTENTE', 'Andrea', '15/07/2026', 90000, 5, null, null, null],
            ['555555', 'Cliente', 'METALIFE', 'Andrea', '15/07/2026', 90000, null, null, null, null],
        ]);

        $this->actingAs(User::factory()->create())
            ->post(route('policies.import'), ['file' => $file]);

        $result = session('import_result');

        $this->assertSame(0, $result['imported']);
        $this->assertCount(3, $result['errors']);
        $this->assertStringContainsString('Fila 2:', $result['errors'][0]);
        $this->assertStringContainsString('agente', $result['errors'][0]);
        $this->assertStringContainsString('producto', $result['errors'][1]);
        $this->assertStringContainsString('costo de facturación', $result['errors'][2]);
        $this->assertDatabaseCount('policies', 0);
    }

    public function test_import_always_applies_ten_percent_isr_even_if_the_file_has_the_column(): void
    {
        Agent::factory()->create(['name' => 'Andrea', 'is_active' => true]);
        $this->createCommissionScheme();

        // Plantillas anteriores incluyen la columna de ISR: se ignora y se guarda 10%
        $legacyHeaders = self::HEADERS;
        array_splice($legacyHeaders, 6, 0, ['% Retención ISR']);

        $file = $this->makeSpreadsheet([
            ['888888', 'Cliente', 'METALIFE', 'Andrea', '15/07/2026', 90000, 25, 5, null, 'Activa', 'Efectivo'],
        ], $legacyHeaders);

        $this->actingAs(User::factory()->create())
            ->post(route('policies.import'), ['file' => $file]);

        $this->assertEquals('10.00', Policy::where('policy_number', '888888')->firstOrFail()->isr_retention);
    }

    public function test_import_requires_a_file(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('policies.import'))
            ->assertSessionHasErrors('file');
    }

    public function test_template_download_returns_an_excel_file(): void
    {
        Agent::factory()->create(['name' => 'Andrea', 'is_active' => true]);
        $this->createCommissionScheme();

        $response = $this->actingAs(User::factory()->create())
            ->get(route('policies.import.template'));

        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('plantilla-importacion-polizas.xlsx', $response->headers->get('Content-Disposition'));

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'policy_template_'.uniqid().'.xlsx';
        file_put_contents($path, $response->streamedContent());

        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertSame('Número de póliza', $sheet->getCell('A1')->getValue());
        $this->assertSame('% Impuesto cedular', $sheet->getCell('G1')->getValue());
        $this->assertSame('% Costo facturación', $sheet->getCell('H1')->getValue());
        $this->assertSame('Estatus', $sheet->getCell('I1')->getValue());
        $this->assertSame('Conducto de pago', $sheet->getCell('J1')->getValue());
        // La plantilla se descarga vacía: solo encabezados, listas desplegables y catálogo
        $this->assertNull($sheet->getCell('A2')->getValue());
        $this->assertNull($sheet->getCell('D2')->getValue());
        $this->assertSame('Catálogo', $spreadsheet->getSheet(1)->getTitle());
        $this->assertSame('METALIFE', $spreadsheet->getSheet(1)->getCell('A2')->getValue());

        // La retención de ISR ya no se captura en la plantilla
        $this->assertNull($sheet->getCell('K1')->getValue());

        // Cada columna de catálogo es una lista desplegable
        $productValidation = $this->validationFor($sheet, 'C');
        $this->assertNotNull($productValidation);
        $this->assertSame(DataValidation::TYPE_LIST, $productValidation->getType());
        $this->assertSame("'Catálogo'!\$A\$2:\$A\$2", $productValidation->getFormula1());

        $agentValidation = $this->validationFor($sheet, 'D');
        $this->assertNotNull($agentValidation);
        $this->assertSame(DataValidation::TYPE_LIST, $agentValidation->getType());
        $this->assertSame("'Catálogo'!\$A\$5:\$A\$5", $agentValidation->getFormula1());
        $this->assertSame('Andrea', $spreadsheet->getSheet(1)->getCell('A5')->getValue());

        $this->assertSame('"Activa,No tomada,Pagada"', $this->validationFor($sheet, 'I')?->getFormula1());
        $this->assertSame('"Efectivo,Automático"', $this->validationFor($sheet, 'J')?->getFormula1());

        // showDropDown invertido en OOXML: true = flecha visible
        $this->assertTrue($this->validationFor($sheet, 'J')?->getShowDropDown());

        $spreadsheet->disconnectWorksheets();
        unlink($path);
    }

    public function test_template_omits_agent_dropdown_when_there_are_no_active_agents(): void
    {
        $this->createCommissionScheme();

        $response = $this->actingAs(User::factory()->create())
            ->get(route('policies.import.template'));

        $response->assertOk();

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'policy_template_'.uniqid().'.xlsx';
        file_put_contents($path, $response->streamedContent());

        $spreadsheet = IOFactory::load($path);

        $this->assertNull($this->validationFor($spreadsheet->getActiveSheet(), 'D'));

        $spreadsheet->disconnectWorksheets();
        unlink($path);
    }

    /**
     * Localiza la validación de datos aplicada a la columna indicada.
     */
    private function validationFor(Worksheet $sheet, string $column): ?DataValidation
    {
        foreach ($sheet->getDataValidationCollection() as $validation) {
            if (str_starts_with((string) $validation->getSqref(), $column.'2:')) {
                return $validation;
            }
        }

        return null;
    }

    private function createCommissionScheme(): void
    {
        $scheme = Scheme::create([
            'name' => 'Comisión de prueba',
            'type' => 'commission',
            'target' => 'agent',
            'is_active' => true,
        ]);

        $version = $scheme->versions()->create([
            'version_name' => 'v1',
            'starts_at' => now()->subMonth()->toDateString(),
        ]);

        $version->tiers()->create([
            'conditions' => ['product_type' => 'METALIFE'],
            'agent_percentage' => 56,
            'promoter_percentage' => 14,
        ]);
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<int, string>|null  $headers
     */
    private function makeSpreadsheet(array $rows, ?array $headers = null): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($headers ?? self::HEADERS, null, 'A1');
        $sheet->fromArray($rows, null, 'A2');

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'policy_import_'.uniqid().'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile(
            $path,
            'polizas.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );
    }
}
