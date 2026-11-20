<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\Policy;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class PolicyImportService
{
    /**
     * Columnas esperadas en la plantilla: campo => etiqueta en español.
     */
    public const COLUMNS = [
        'policy_number' => 'Número de póliza',
        'client_name' => 'Cliente',
        'product_type' => 'Producto',
        'agent_name' => 'Agente',
        'issue_date' => 'Fecha de emisión',
        'premium_amount' => 'PNA',
        'isr_retention' => '% Retención ISR',
        'cedular_tax' => '% Impuesto cedular',
        'billing_retention' => '% Costo facturación',
        'status' => 'Estatus',
        'payment_method' => 'Conducto de pago',
    ];

    private const REQUIRED_COLUMNS = [
        'policy_number',
        'product_type',
        'agent_name',
        'issue_date',
        'premium_amount',
        'billing_retention',
    ];

    /**
     * Encabezados aceptados por columna (ya normalizados: sin acentos ni signos).
     */
    private const COLUMN_ALIASES = [
        'policy_number' => ['numerodepoliza', 'numeropoliza', 'nopoliza', 'poliza', 'policynumber'],
        'client_name' => ['cliente', 'nombredelcliente', 'nombredelasegurado'],
        'product_type' => ['producto', 'tipodeproducto', 'tipoproducto'],
        'agent_name' => ['agente', 'nombredelagente', 'agenteasignado'],
        'issue_date' => ['fechadeemision', 'fechaemision', 'fecha'],
        'premium_amount' => ['pna', 'prima', 'primatotal', 'primanetaacumulada'],
        'billing_retention' => ['costofacturacion', 'costodefacturacion', 'facturacion'],
        'isr_retention' => ['retencionisr', 'isr'],
        'cedular_tax' => ['impuestocedular', 'cedular'],
        'status' => ['estatus', 'status'],
        'payment_method' => ['conductodepago', 'conducto', 'formadepago', 'metododepago', 'pagoconducto'],
    ];

    public function __construct(private ProductCommissionService $productCommissionService) {}

    /**
     * Lee el archivo (xlsx, xls o csv) y registra las pólizas válidas.
     *
     * @return array{imported: int, errors: string[]}
     */
    public function import(UploadedFile $file): array
    {
        $rows = $this->readRows($file);

        if ($rows === []) {
            return ['imported' => 0, 'errors' => ['El archivo no contiene información.']];
        }

        $headerMap = $this->mapHeaders(array_shift($rows));

        $missingColumns = array_diff(self::REQUIRED_COLUMNS, array_keys($headerMap));
        if ($missingColumns !== []) {
            $labels = array_map(fn ($column) => '"'.self::COLUMNS[$column].'"', $missingColumns);

            return [
                'imported' => 0,
                'errors' => ['Faltan columnas obligatorias en el archivo: '.implode(', ', $labels).'.'],
            ];
        }

        $agents = $this->agentMap();
        $productCommissionMap = $this->productCommissionService->map();
        $products = $this->productMap($productCommissionMap);
        $seenPolicyNumbers = [];
        $imported = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            if ($this->isEmptyRow($row)) {
                continue;
            }

            $rowNumber = $index + 2;
            $data = $this->extractRow($row, $headerMap);
            $rowErrors = [];

            $policyNumber = preg_replace('/\D/', '', (string) ($data['policy_number'] ?? ''));
            if ($policyNumber === '') {
                $rowErrors[] = 'el número de póliza es obligatorio y solo debe contener números.';
            } elseif (isset($seenPolicyNumbers[$policyNumber])) {
                $rowErrors[] = "el número de póliza {$policyNumber} está repetido en el archivo.";
            } elseif (Policy::where('policy_number', $policyNumber)->exists()) {
                $rowErrors[] = "el número de póliza {$policyNumber} ya está registrado.";
            }

            $agent = $agents[$this->normalize((string) ($data['agent_name'] ?? ''))] ?? null;
            if (! $agent) {
                $rowErrors[] = 'el agente "'.trim((string) ($data['agent_name'] ?? '')).'" no existe o no está activo.';
            }

            $productType = $products[$this->normalize((string) ($data['product_type'] ?? ''))] ?? null;
            if (! $productType) {
                $rowErrors[] = 'el producto "'.trim((string) ($data['product_type'] ?? '')).'" no está configurado en los esquemas de comisión.';
            }

            $issueDate = $this->parseDate($data['issue_date'] ?? null);
            if (! $issueDate) {
                $rowErrors[] = 'la fecha de emisión no es válida (usa el formato dd/mm/aaaa).';
            }

            $pna = $this->parseNumber($data['premium_amount'] ?? null);
            if ($pna === null || $pna < 0) {
                $rowErrors[] = 'el PNA es obligatorio y debe ser un número mayor o igual a cero.';
            }

            $billingRetention = $this->parseNumber($data['billing_retention'] ?? null);
            if ($billingRetention === null) {
                $rowErrors[] = 'el costo de facturación es obligatorio.';
            }

            $isrRetention = $this->parseNumber($data['isr_retention'] ?? null) ?? 10.0;
            $cedularTax = $this->parseNumber($data['cedular_tax'] ?? null) ?? 5.0;

            $status = $this->parseStatus($data['status'] ?? null);
            if ($status === null) {
                $rowErrors[] = 'el estatus no es válido (usa Activa, No tomada o Pagada).';
            }

            $paymentMethod = $this->parsePaymentMethod($data['payment_method'] ?? null);
            if ($paymentMethod === null) {
                $rowErrors[] = 'el conducto de pago no es válido (usa Efectivo o Automático).';
            }

            if ($rowErrors !== []) {
                $errors[] = "Fila {$rowNumber}: ".implode(' ', $rowErrors);

                continue;
            }

            $seenPolicyNumbers[$policyNumber] = true;

            try {
                $pca = max(0, $pna - Policy::PCA_DEDUCTION);
                $commission = $productCommissionMap[$productType];

                Policy::create([
                    'agent_id' => $agent->id,
                    'policy_number' => $policyNumber,
                    'client_name' => $this->cleanString($data['client_name'] ?? null),
                    'issue_date' => $issueDate->toDateString(),
                    'premium_amount' => round($pna, 2),
                    'commission_percentage' => $commission['agent_percentage'],
                    'commission_amount' => round($pca * ($commission['agent_percentage'] / 100), 2),
                    'promoter_commission_percentage' => $commission['promoter_percentage'],
                    'promoter_commission_amount' => round($pca * ($commission['promoter_percentage'] / 100), 2),
                    'isr_retention' => $isrRetention,
                    'cedular_tax' => $cedularTax,
                    'billing_retention' => $billingRetention,
                    'status' => $status,
                    'product_type' => $productType,
                    'payment_method' => $paymentMethod,
                ]);

                $imported++;
            } catch (\Throwable $exception) {
                Log::error('Error al importar póliza', [
                    'row' => $rowNumber,
                    'policy_number' => $policyNumber,
                    'message' => $exception->getMessage(),
                ]);
                $errors[] = "Fila {$rowNumber}: no se pudo guardar la póliza.";
            }
        }

        return ['imported' => $imported, 'errors' => $errors];
    }

    /**
     * Lee todas las filas de la primera hoja del archivo.
     *
     * @return array<int, array<int, mixed>>
     */
    private function readRows(UploadedFile $file): array
    {
        $path = $file->getRealPath();

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);

        $spreadsheet = $reader->load($path);
        $rows = $spreadsheet->getSheet(0)->toArray(null, true, false, false);
        $spreadsheet->disconnectWorksheets();

        return $rows;
    }

    /**
     * @param  array<int, mixed>  $headerRow
     * @return array<string, int> campo => índice de columna
     */
    private function mapHeaders(array $headerRow): array
    {
        $headerMap = [];

        foreach ($headerRow as $index => $header) {
            $normalized = $this->normalize((string) $header);

            foreach (self::COLUMN_ALIASES as $field => $aliases) {
                if (in_array($normalized, $aliases, true) && ! isset($headerMap[$field])) {
                    $headerMap[$field] = $index;
                }
            }
        }

        return $headerMap;
    }

    /**
     * @param  array<int, mixed>  $row
     * @param  array<string, int>  $headerMap
     * @return array<string, mixed>
     */
    private function extractRow(array $row, array $headerMap): array
    {
        $data = [];

        foreach ($headerMap as $field => $index) {
            $data[$field] = $row[$index] ?? null;
        }

        return $data;
    }

    /**
     * @return array<string, Agent>
     */
    private function agentMap(): array
    {
        $map = [];

        foreach (Agent::where('is_active', true)->orderBy('name')->get() as $agent) {
            $map[$this->normalize($agent->name)] = $agent;
        }

        return $map;
    }

    /**
     * @param  array<string, array{agent_percentage: float, promoter_percentage: float}>  $productCommissionMap
     * @return array<string, string> nombre normalizado => nombre real del producto
     */
    private function productMap(array $productCommissionMap): array
    {
        $map = [];

        foreach (array_keys($productCommissionMap) as $product) {
            $map[$this->normalize($product)] = $product;
        }

        return $map;
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value) && (float) $value > 1000) {
            return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->startOfDay();
        }

        $text = trim((string) $value);

        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'd/m/y', 'm/d/Y', 'Y/m/d'] as $format) {
            $date = \DateTime::createFromFormat('!'.$format, $text);
            if ($date && $date->format($format) === $text) {
                return Carbon::instance($date)->startOfDay();
            }
        }

        try {
            return Carbon::parse($text)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseNumber(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        $clean = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', (string) $value));

        return $clean === '' || ! is_numeric($clean) ? null : (float) $clean;
    }

    private function parseStatus(mixed $value): ?string
    {
        $normalized = $this->normalize((string) $value);

        if ($normalized === '') {
            return Policy::STATUS_ACTIVA;
        }

        foreach (Policy::STATUSES as $status) {
            if ($this->normalize($status) === $normalized) {
                return $status;
            }
        }

        return null;
    }

    private function parsePaymentMethod(mixed $value): ?string
    {
        $normalized = $this->normalize((string) $value);

        if ($normalized === '') {
            return Policy::PAYMENT_METHOD_CASH;
        }

        foreach (Policy::PAYMENT_METHODS as $paymentMethod) {
            if ($this->normalize($paymentMethod) === $normalized) {
                return $paymentMethod;
            }
        }

        return null;
    }

    private function cleanString(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }

    /**
     * @param  array<int, mixed>  $row
     */
    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) ($cell ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }

    private function normalize(string $value): string
    {
        $ascii = strtolower(Str::ascii(trim($value)));

        return preg_replace('/[^a-z0-9]/', '', $ascii) ?? '';
    }
}
