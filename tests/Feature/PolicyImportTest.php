<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Policy;
use App\Models\Scheme;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
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
        '% Retención ISR',
        '% Impuesto cedular',
        'Estatus',
        'Conducto de pago',
    ];

    public function test_import_creates_policies_with_commissions_based_on_pca(): void
    {
        Agent::factory()->create(['name' => 'Andrea López', 'is_active' => true]);
        $this->createCommissionScheme();

        $file = $this->makeSpreadsheet([
            ['111111', 'Juan Pérez', 'METALIFE', 'Andrea López', '15/07/2026', 90000, 5, 10, 5, 'Pagada', 'Automático'],
            ['222222', '', 'METALIFE', 'andrea lopez', '2026-07-16', 50000, 4, null, null, '', null],
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
        $this->assertEquals('5.00', $policy->cedular_tax);
        $this->assertEquals('Pagada', $policy->status);
        $this->assertEquals('2026-07-15', $policy->issue_date->toDateString());
        $this->assertEquals('METALIFE', $policy->product_type);
        $this->assertEquals('Automático', $policy->payment_method);

        $secondPolicy = Policy::where('policy_number', '222222')->firstOrFail();

        $this->assertEquals('Activa', $secondPolicy->status);
        $this->assertEquals('10.00', $secondPolicy->isr_retention);
        $this->assertEquals('5.00', $secondPolicy->cedular_tax);
        $this->assertEquals('Efectivo', $secondPolicy->payment_method);
        $this->assertNull($secondPolicy->client_name);
    }

    public function test_import_reports_rows_with_errors_and_skips_them(): void
    {
        Agent::factory()->create(['name' => 'Andrea', 'is_active' => true]);
        $this->createCommissionScheme();

        $file = $this->makeSpreadsheet([
            ['333333', 'Cliente', 'METALIFE', 'Agente inexistente', '15/07/2026', 90000, 5, null, null, null, null],
            ['444444', 'Cliente', 'PRODUCTO INEXISTENTE', 'Andrea', '15/07/2026', 90000, 5, null, null, null, null],
            ['555555', 'Cliente', 'METALIFE', 'Andrea', '15/07/2026', 90000, null, null, null, null, null],
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
        $this->assertSame('% Retención ISR', $sheet->getCell('G1')->getValue());
        $this->assertSame('% Impuesto cedular', $sheet->getCell('H1')->getValue());
        $this->assertSame('% Costo facturación', $sheet->getCell('I1')->getValue());
        $this->assertSame('Estatus', $sheet->getCell('J1')->getValue());
        $this->assertSame('Conducto de pago', $sheet->getCell('K1')->getValue());
        $this->assertSame('878901', $sheet->getCell('A2')->getValue());
        $this->assertSame('Catálogo', $spreadsheet->getSheet(1)->getTitle());
        $this->assertSame('METALIFE', $spreadsheet->getSheet(1)->getCell('A2')->getValue());

        $spreadsheet->disconnectWorksheets();
        unlink($path);
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
     */
    private function makeSpreadsheet(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(self::HEADERS, null, 'A1');
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
