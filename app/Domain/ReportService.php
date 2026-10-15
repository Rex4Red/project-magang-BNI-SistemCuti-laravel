<?php

namespace App\Domain;

use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class ReportService
{
    public function download(array $rows, array $actor, string $month, string $format): StreamedResponse
    {
        $labels = ['PENDING_SDM' => 'Menunggu review', 'APPROVED' => 'Disetujui', 'REJECTED' => 'Ditolak', 'WITHDRAWN' => 'Ditarik'];
        $headers = ['No.', 'Nomor pengajuan', 'Nama pegawai', 'Posisi', 'Kategori', 'Jenis cuti', 'Mulai', 'Akhir', 'Hari kerja', 'Status', 'Waktu Diajukan'];
        $data = [];
        foreach ($rows as $index => $leave) {
            $data[] = [$index + 1, $leave['number'], $leave['employeeName'], $leave['positionName'] ?? $leave['position'], $leave['category'] === 'REGULAR' ? 'Reguler' : 'Darurat', $leave['subtype'], $leave['effectiveStart'], $leave['effectiveEnd'], $leave['duration'], $labels[$leave['status']] ?? $leave['status'], $this->submittedAt($leave)];
        }
        $type = ['xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xls' => 'application/vnd.ms-excel', 'csv' => 'text/csv; charset=UTF-8'][$format];

        return response()->streamDownload(function () use ($data, $headers, $actor, $month, $format) {
            if ($format === 'csv') {
                $stream = fopen('php://output', 'wb');
                fwrite($stream, "\xEF\xBB\xBF");
                foreach ([$headers, ...$data] as $row) {
                    fputcsv($stream, array_map(fn ($value) => is_string($value) && preg_match('/^[=+@-]/', $value) ? "'".$value : $value, $row), ',', '"', '');
                }
                fclose($stream);

                return;
            }
            $workbook = new Spreadsheet;
            $workbook->getProperties()->setCreator('BNI Sistem Cuti')->setLastModifiedBy($actor['name']);
            $sheet = $workbook->getActiveSheet()->setTitle('Monitoring Cuti Pegawai');
            $sheet->mergeCells('A1:K1')->setCellValue('A1', 'MONITORING CUTI PEGAWAI BNI');
            $sheet->mergeCells('A2:K2')->setCellValue('A2', $month === 'all' ? 'Semua bulan' : $month);
            $sheet->mergeCells('A3:K3')->setCellValueExplicit('A3', 'Dicetak oleh '.$actor['name'].' · '.now('Asia/Jakarta')->format('d/m/Y H:i'), DataType::TYPE_STRING);
            foreach ([$headers, ...$data] as $index => $row) {
                foreach ($row as $column => $value) {
                    $sheet->setCellValueExplicit([$column + 1, $index + 5], $value, is_int($value) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
                }
            }
            $sheet->getStyle('A1:K1')->getFont()->setBold(true)->setSize(16);
            $sheet->getStyle('A5:K5')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('006674');
            $sheet->getStyle('A5:K5')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
            foreach (range('A', 'K') as $column) {
                $sheet->getColumnDimension($column)->setAutoSize(true);
            }
            $sheet->freezePane('A6')->setAutoFilter('A5:K'.(count($data) + 5));
            $sheet->getPageSetup()->setOrientation('landscape')->setPaperSize(9)->setFitToWidth(1)->setFitToHeight(0);
            ($format === 'xlsx' ? new Xlsx($workbook) : new Xls($workbook))->save('php://output');
            $workbook->disconnectWorksheets();
        }, 'monitoring-cuti-'.$month.'.'.$format, ['Content-Type' => $type, 'Cache-Control' => 'private, no-store']);
    }

    private function submittedAt(array $leave): string
    {
        $timestamp = ($leave['submittedAt'] ?? '') ?: ($leave['createdAt'] ?? '');
        if (! is_string($timestamp) || $timestamp === '') {
            return '-';
        }
        try {
            return CarbonImmutable::parse($timestamp, 'UTC')->setTimezone('Asia/Jakarta')->locale('id')->translatedFormat('j M Y H:i:s').' WIB';
        } catch (Throwable) {
            return $timestamp;
        }
    }
}
