<?php

namespace App\Domain;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class ReportService
{
    public function __construct(private CutiStore $store) {}

    public function calendarData(array $actor, string $month, string $position = 'ALL'): array
    {
        $policy = $this->store->policy($actor['unit']);
        $positions = LeaveRules::positions($policy);
        CutiError::check($position === 'ALL' || in_array($position, array_column($positions, 0), true), 'Posisi tidak tersedia.');
        $employees = array_column($this->store->employees($actor['unit']), null, 'id');
        $outlets = array_column($this->store->outlets($actor['unit']), 'name', 'id');
        $positionNames = array_column($positions, 1, 0);
        $days = array_map(fn ($date) => ['date' => $date, 'number' => (int) substr($date, 8), 'off' => ! LeaveRules::isWorking($date, $policy['calendar']), 'label' => $policy['calendar']['exceptions'][$date]['label'] ?? ''], LeaveRules::monthDays($month));
        $dates = array_column($days, 'date');
        $rows = [];
        foreach ($this->store->requests($actor['unit']) as $leave) {
            if (! in_array($leave['status'], LeaveRules::ACTIVE, true) || ($position !== 'ALL' && $leave['position'] !== $position) || ! array_intersect($dates, $leave['days'])) {
                continue;
            }
            $employee = $employees[$leave['employeeId']] ?? [];
            $replacement = $leave['replacement']['employeeName'] ?? '';
            $cells = [];
            $previousActive = false;
            foreach ($days as $day) {
                $active = ! $day['off'] && in_array($day['date'], $leave['days'], true);
                $cells[] = ['active' => $active, 'text' => $active && ! $previousActive ? ($replacement ?: $leave['subtype']) : ''];
                $previousActive = $active;
            }
            $rows[] = [
                'id' => $leave['id'], 'name' => $leave['employeeName'],
                'npp' => ($leave['npp'] ?? '') ?: ($employee['npp'] ?? ''),
                'position' => $leave['positionName'] ?? $positionNames[$leave['position']] ?? $leave['position'],
                'unit' => $leave['outletName'] ?? $outlets[$leave['outletId'] ?? CutiStore::outletId($employee ?: ['unit' => $actor['unit']])] ?? '—',
                'replacement' => $replacement ?: 'Tanpa PGS', 'status' => config('cuti.public_form') ? 'RECORDED' : $leave['status'],
                'start' => $leave['effectiveStart'], 'cells' => $cells,
            ];
        }
        usort($rows, fn ($a, $b) => strcasecmp($a['name'], $b['name']) ?: strcmp($a['start'], $b['start']) ?: strcmp($a['id'], $b['id']));

        return [
            'month' => $month, 'monthLabel' => CarbonImmutable::parse($month.'-01')->locale('id')->translatedFormat('F Y'),
            'recordOnly' => (bool) config('cuti.public_form'),
            'unitName' => DB::table('units')->where('id', $actor['unit'])->value('name'),
            'positions' => $positions, 'days' => $days, 'rows' => $rows,
        ];
    }

    public function downloadCalendar(array $data, array $actor): StreamedResponse
    {
        return response()->streamDownload(function () use ($data, $actor) {
            $workbook = new Spreadsheet;
            $workbook->getDefaultStyle()->getFont()->setName('Arial')->setSize(11);
            $workbook->getProperties()->setCreator('BNI Sistem Cuti')->setLastModifiedBy($actor['name']);
            $sheet = $workbook->getActiveSheet()->setTitle('Rekap Kalender Cuti');
            $dayCount = count($data['days']);
            $lastColumn = Coordinate::stringFromColumnIndex(6 + $dayCount);
            $lastDayColumn = Coordinate::stringFromColumnIndex(5 + $dayCount);
            $lastRow = max(7, 6 + count($data['rows']));
            $sheet->mergeCells('A1:'.$lastColumn.'1')->setCellValue('A1', 'REKAP KALENDER CUTI PEGAWAI');
            $sheet->mergeCells('A2:'.$lastColumn.'2')->setCellValueExplicit('A2', $data['unitName'].' · '.$data['monthLabel'], DataType::TYPE_STRING);
            $sheet->mergeCells('A3:'.$lastColumn.'3')->setCellValue('A3', $data['recordOnly'] ? 'Hijau: Tercatat | Merah: Hari libur | Isi blok: Pengganti / jenis cuti' : 'Hijau: Disetujui | Kuning: Menunggu review | Merah: Hari libur | Isi blok: Pengganti / jenis cuti');
            $sheet->mergeCells('F6:'.$lastDayColumn.'6')->setCellValue('F6', $data['monthLabel']);
            foreach (['NO', 'NAMA PEGAWAI', 'NPP', 'POSISI', 'UNIT'] as $index => $label) {
                $column = Coordinate::stringFromColumnIndex($index + 1);
                $sheet->mergeCells($column.'5:'.$column.'6')->setCellValue($column.'5', $label);
            }
            $sheet->mergeCells($lastColumn.'5:'.$lastColumn.'6')->setCellValue($lastColumn.'5', 'PENGGANTI / PGS');
            foreach ($data['days'] as $index => $day) {
                $sheet->setCellValue([$index + 6, 5], $day['number']);
                if ($day['off']) {
                    $column = Coordinate::stringFromColumnIndex($index + 6);
                    $sheet->getStyle($column.'5:'.$column.$lastRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E69A9F');
                }
            }
            foreach ($data['rows'] as $index => $row) {
                $rowNumber = $index + 7;
                foreach ([$index + 1, $row['name'], $row['npp'], $row['position'], $row['unit']] as $column => $value) {
                    $sheet->setCellValueExplicit([$column + 1, $rowNumber], $value, is_int($value) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
                }
                $sheet->setCellValueExplicit($lastColumn.$rowNumber, $row['replacement'], DataType::TYPE_STRING);
                $segmentStart = null;
                foreach ($row['cells'] as $dayIndex => $cell) {
                    $column = Coordinate::stringFromColumnIndex($dayIndex + 6);
                    if ($cell['active']) {
                        $sheet->getStyle($column.$rowNumber)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($row['status'] === 'PENDING_SDM' ? 'F3D16B' : '72B687');
                        if ($segmentStart === null) {
                            $segmentStart = $dayIndex + 6;
                            $sheet->setCellValueExplicit($column.$rowNumber, $cell['text'], DataType::TYPE_STRING);
                        }
                    }
                    if ($segmentStart !== null && (! $cell['active'] || $dayIndex === $dayCount - 1)) {
                        $segmentEnd = $cell['active'] ? $dayIndex + 6 : $dayIndex + 5;
                        if ($segmentEnd > $segmentStart) {
                            $sheet->mergeCells(Coordinate::stringFromColumnIndex($segmentStart).$rowNumber.':'.Coordinate::stringFromColumnIndex($segmentEnd).$rowNumber);
                        }
                        $segmentStart = null;
                    }
                }
                $sheet->getRowDimension($rowNumber)->setRowHeight(60);
            }
            if (! $data['rows']) {
                $sheet->mergeCells('A7:E7')->setCellValue('A7', 'Belum ada cuti aktif pada periode ini.');
            }
            $sheet->getStyle('A1:'.$lastColumn.$lastRow)->getFont()->setName('Arial')->setSize(11);
            $sheet->getStyle('A1:'.$lastColumn.$lastRow)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
            $sheet->getStyle('A5:'.$lastColumn.$lastRow)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('525252');
            $sheet->getStyle('A5:'.$lastColumn.'6')->getFont()->setBold(true);
            $sheet->getStyle('A5:'.$lastColumn.'6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('A1:'.$lastColumn.'2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(18);
            $sheet->getStyle('A3')->getFont()->setSize(10);
            $sheet->getRowDimension(1)->setRowHeight(26);
            $sheet->getRowDimension(2)->setRowHeight(22);
            $sheet->getRowDimension(3)->setRowHeight(24);
            $sheet->getRowDimension(5)->setRowHeight(21);
            $sheet->getRowDimension(6)->setRowHeight(21);
            foreach (['A' => 6, 'B' => 34, 'C' => 15, 'D' => 17, 'E' => 25] as $column => $width) {
                $sheet->getColumnDimension($column)->setWidth($width);
            }
            for ($column = 6; $column < 6 + $dayCount; $column++) {
                $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->setWidth(7);
            }
            $sheet->getColumnDimension($lastColumn)->setWidth(28);
            $sheet->freezePane('F7');
            $sheet->getSheetView()->setZoomScale(100);
            $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A3)->setFitToWidth(1)->setFitToHeight(0)->setRowsToRepeatAtTop([1, 6])->setPrintArea('A1:'.$lastColumn.$lastRow);
            $sheet->getPageMargins()->setTop(0.25)->setBottom(0.25)->setLeft(0.2)->setRight(0.2);
            (new Xlsx($workbook))->save('php://output');
            $workbook->disconnectWorksheets();
        }, 'rekap-kalender-cuti-'.$data['month'].'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'private, no-store']);
    }

    public function download(array $rows, array $actor, string $month, string $format): StreamedResponse
    {
        $labels = ['PENDING_SDM' => 'Menunggu review', 'APPROVED' => 'Disetujui', 'RECORDED' => 'Tercatat', 'REJECTED' => 'Ditolak', 'WITHDRAWN' => 'Ditarik'];
        if (config('cuti.public_form')) {
            $labels['PENDING_SDM'] = $labels['APPROVED'] = 'Tercatat';
        }
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
            $workbook->getDefaultStyle()->getFont()->setName('Arial')->setSize(11);
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
