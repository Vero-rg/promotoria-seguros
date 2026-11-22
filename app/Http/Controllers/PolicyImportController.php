<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportPoliciesRequest;
use App\Models\Agent;
use App\Services\PolicyImportService;
use App\Services\ProductCommissionService;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PolicyImportController extends Controller
{
    private const TEMPLATE_FILE_NAME = 'plantilla-importacion-polizas.xlsx';

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

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Pólizas');
        $sheet->fromArray(array_values(PolicyImportService::COLUMNS), null, 'A1');
        $sheet->fromArray([
            '878901',
            'Juan Pérez',
            'METALIFE',
            Agent::where('is_active', true)->orderBy('name')->value('name') ?? 'Nombre del agente',
            '15/07/2026',
            90000,
            10,
            0,
            5,
            'Activa',
            'Efectivo',
        ], null, 'A2');

        $sheet->getStyle('A1:K1')->getFont()->setBold(true);
        $sheet->freezePane('A2');

        // El número de póliza se captura como texto para no perder dígitos en Excel
        $sheet->getStyle('A2:A5000')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        $sheet->setCellValueExplicit('A2', '878901', DataType::TYPE_STRING);

        $widths = ['A' => 22, 'B' => 28, 'C' => 18, 'D' => 28, 'E' => 18, 'F' => 16, 'G' => 20, 'H' => 22, 'I' => 22, 'J' => 14, 'K' => 20];
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

        $row++;
        $catalog->setCellValue("A{$row}", 'Agentes activos');
        $catalog->getStyle("A{$row}")->getFont()->setBold(true);
        $row++;

        foreach (Agent::where('is_active', true)->orderBy('name')->pluck('name') as $agentName) {
            $catalog->setCellValue("A{$row}", $agentName);
            $row++;
        }

        $catalog->getColumnDimension('A')->setWidth(32);
        $catalog->getColumnDimension('B')->setWidth(20);
        $catalog->getColumnDimension('C')->setWidth(22);

        // La plantilla debe abrirse en la hoja de captura, no en el catálogo
        $spreadsheet->setActiveSheetIndex(0);

        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, self::TEMPLATE_FILE_NAME, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
