<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportPoliciesRequest;
use App\Models\Agent;
use App\Models\Policy;
use App\Services\PolicyImportService;
use App\Services\ProductCommissionService;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PolicyImportController extends Controller
{
    private const TEMPLATE_FILE_NAME = 'plantilla-importacion-polizas.xlsx';

    /**
     * Última fila de captura que incluye formato y validaciones en la plantilla.
     */
    private const TEMPLATE_LAST_ROW = 5000;

    public function __construct(
        private PolicyImportService $importService,
        private ProductCommissionService $productCommissionService,
    ) {}

    /**
     * Importa pólizas desde un archivo de Excel o CSV.
     */
    public function import(ImportPoliciesRequest $request)
    {
        $result = $this->importService->import($request->file('file'));

        $message = $result['imported'] === 1
            ? 'Se importó 1 póliza correctamente.'
            : "Se importaron {$result['imported']} pólizas correctamente.";

        if ($result['errors'] !== []) {
            $message .= ' '.count($result['errors']).' fila(s) con errores.';
        }

        return back()
            ->with('success', $message)
            ->with('import_result', $result);
    }

    /**
     * Descarga la plantilla de Excel para la importación de pólizas.
     */
    public function template(): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;

        $agents = Agent::where('is_active', true)->orderBy('name')->pluck('name');

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Pólizas');
        $sheet->fromArray(array_values(PolicyImportService::COLUMNS), null, 'A1');

        $sheet->getStyle('A1:J1')->getFont()->setBold(true);
        $sheet->freezePane('A2');

        // El número de póliza se captura como texto para no perder dígitos en Excel
        $sheet->getStyle('A2:A'.self::TEMPLATE_LAST_ROW)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);

        $widths = ['A' => 22, 'B' => 28, 'C' => 18, 'D' => 28, 'E' => 18, 'F' => 16, 'G' => 22, 'H' => 22, 'I' => 14, 'J' => 20];
        foreach ($widths as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        $catalog = $spreadsheet->createSheet();
        $catalog->setTitle('Catálogo');
        $catalog->fromArray(['Producto', '% comisión agente', '% comisión promotor'], null, 'A1');
        $catalog->getStyle('A1:C1')->getFont()->setBold(true);

        $row = 2;
        foreach ($this->productCommissionService->map() as $product => $percentages) {
            $catalog->setCellValue("A{$row}", $product);
            $catalog->setCellValue("B{$row}", $percentages['agent_percentage']);
            $catalog->setCellValue("C{$row}", $percentages['promoter_percentage']);
            $row++;
        }

        $lastProductRow = $row - 1;
        $row++;
        $catalog->setCellValue("A{$row}", 'Agentes activos');
        $catalog->getStyle("A{$row}")->getFont()->setBold(true);
        $row++;

        $firstAgentRow = $row;
        foreach ($agents as $agentName) {
            $catalog->setCellValue("A{$row}", $agentName);
            $row++;
        }

        $catalog->getColumnDimension('A')->setWidth(32);
        $catalog->getColumnDimension('B')->setWidth(20);
        $catalog->getColumnDimension('C')->setWidth(22);

        if ($lastProductRow >= 2) {
            $this->addListValidation(
                $sheet,
                'product_type',
                sprintf("'Catálogo'!\$A\$2:\$A\$%d", $lastProductRow),
                'Producto',
                'Selecciona un producto de la lista desplegable.',
            );
        }

        if ($agents->isNotEmpty()) {
            $this->addListValidation(
                $sheet,
                'agent_name',
                sprintf("'Catálogo'!\$A\$%d:\$A\$%d", $firstAgentRow, $row - 1),
                'Agente',
                'Selecciona un agente registrado y activo de la lista desplegable.',
            );
        }

        $this->addListValidation(
            $sheet,
            'status',
            '"'.implode(',', Policy::STATUSES).'"',
            'Estatus',
            'Selecciona un estatus de la lista desplegable.',
        );

        $this->addListValidation(
            $sheet,
            'payment_method',
            '"'.implode(',', Policy::PAYMENT_METHODS).'"',
            'Conducto de pago',
            'Selecciona un conducto de pago de la lista desplegable.',
        );

        // La plantilla debe abrirse en la hoja de captura, no en el catálogo
        $spreadsheet->setActiveSheetIndex(0);

        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, self::TEMPLATE_FILE_NAME, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Restringe una columna de captura a una lista desplegable.
     */
    private function addListValidation(Worksheet $sheet, string $field, string $formula, string $title, string $errorMessage): void
    {
        $columnIndex = array_search($field, array_keys(PolicyImportService::COLUMNS), true) + 1;
        $column = Coordinate::stringFromColumnIndex($columnIndex);

        $validation = new DataValidation;
        $validation->setType(DataValidation::TYPE_LIST);
        $validation->setErrorStyle(DataValidation::STYLE_STOP);
        $validation->setAllowBlank(true);
        // En OOXML el atributo está invertido: la librería necesita true para mostrar la flecha.
        $validation->setShowDropDown(true);
        $validation->setShowInputMessage(true);
        $validation->setShowErrorMessage(true);
        $validation->setPromptTitle($title);
        $validation->setPrompt('Selecciona un valor de la lista desplegable.');
        $validation->setErrorTitle("{$title} no válido");
        $validation->setError($errorMessage);
        $validation->setFormula1($formula);

        $sheet->setDataValidation("{$column}2:{$column}".self::TEMPLATE_LAST_ROW, $validation);
    }
}
