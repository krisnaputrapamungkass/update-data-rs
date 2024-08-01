<?php

namespace App\Http\Controllers;

use App\Models\Data;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class UpdateController extends Controller
{
    private $categories = [
        'Klinis' => [
            'Rekam Medis' => ['rekam medis', 'rm'],
            'Poli Mata' => ['mata'],
            'Poli Bedah' => ['bedah'],
            'Poli Obgyn' => ['obgyn'],
            'Poli THT' => ['tht'],
            'Poli Orthopedi' => ['orthopedi', 'ortopedi'],
            'Poli Jantung' => ['jantung'],
            'Poli Gigi' => ['gigi'],
            'ICU' => ['icu'],
            'Radiologi' => ['radiologi'],
            'Perinatologi' => ['perinatologi', 'perina'],
            'Rehabilitasi Medik' => ['rehabilitasi medik'],
            'IGD' => ['igd'],
        ],
        'Non-Klinis' => [
            'Farmasi' => ['farmasi'],
            'Kesehatan Lingkungan' => ['kesehatan lingkungan', 'kesling'],
            'IBS' => ['ibs'],
            'Litbang' => ['litbang', 'ukm litbang'],
            'Ukm' => ['ukm'],
            'Laboratorium & Pelayanan Darah' => ['laboratorium & pelayanan darah', 'laboratorium'],
            'Akreditasi' => ['akreditasi'],
            'Kasir' => ['kasir'],
            'Anggrek' => ['anggrek', 'unit anggrek'],
            'Jamkes/Pojok JKN' => ['jamkes', 'pojok jkn', 'pojok jkn / loket bpjs', 'jamkes / pojok jkn'],
            'SIMRS' => ['simrs'],
            'Loket TPPRI' => ['loket tppri', 'tppri', 'tppri timur'],
            'Gizi' => ['gizi'],
            'Ranap' => ['ranap'],
            'Bugenvil' => ['bugenvil'],
            'IFRS' => ['ifrs'],
            'Veritatis voluptatem' => ['veritatis voluptatem'],
            'IT' => ['it'],
        ],
    ];

    public function getKomplainData(Request $request)
    {
        $selectedMonth = $request->input('month', Carbon::now()->format('Y-m'));
        Log::info('Selected Month:', ['month' => $selectedMonth]);

        try {
            $data = $this->getProcessedData($selectedMonth);
            
            return response()->json([
                'totalComplaints' => count($data),
                'statusCounts' => $this->getStatusCounts($data),
                'petugasCounts' => $this->getPetugasCounts($data),
                'unitCounts' => $this->getUnitCounts($data),
                'averageResponseTime' => $this->calculateAverageResponseTime($data),
                'averageCompletedResponseTime' => $this->calculateAverageCompletedResponseTime($data),
                'selectedMonth' => $selectedMonth,
                'availableMonths' => $this->getAvailableMonths(),
            ]);
        } catch (\Exception $e) {
            Log::error('Error processing request:', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Server Error: ' . $e->getMessage()], 500);
        }
    }

    private function getProcessedData($selectedMonth)
    {
        $startDate = Carbon::createFromFormat('Y-m', $selectedMonth)->startOfMonth();
        $endDate = Carbon::createFromFormat('Y-m', $selectedMonth)->endOfMonth();

        return Data::where('form_id', 3)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->get()
            ->map(function ($data) {
                $parsedJson = $data->json[0] ?? [];
                $extractedData = $this->extractDataFromJson($parsedJson);
                $responTime = $this->calculateResponseTime($data->datetime_masuk, $data->datetime_selesai);

                return [
                    'id' => $data->id,
                    'Nama Pelapor' => $extractedData['namaPelapor'],
                    'Nama Petugas' => $this->normalizePetugasNames($data->petugas),
                    'created_at' => $this->formatDateTime($data->created_at),
                    'datetime_masuk' => $this->formatDateTime($data->datetime_masuk),
                    'datetime_pengerjaan' => $this->formatDateTime($data->datetime_pengerjaan),
                    'datetime_selesai' => $this->formatDateTime($data->datetime_selesai),
                    'status' => $extractedData['status'] ?? $data->status ?? '',
                    'is_pending' => $data->is_pending,
                    'Nama Unit/Poli' => $this->normalizeUnitNames($extractedData['namaUnit']),
                    'respon_time' => $responTime['formatted'],
                    'respon_time_minutes' => $responTime['minutes'],
                ];
            })->toArray();
    }

    private function extractDataFromJson($parsedJson)
    {
        $data = ['namaPelapor' => '', 'namaUnit' => '', 'status' => ''];
        foreach ($parsedJson as $item) {
            if (isset($item['name'], $item['value'])) {
                switch ($item['name']) {
                    case 'text-1709615631557-0':
                        $data['namaPelapor'] = $item['value'];
                        break;
                    case 'text-1709615712000-0':
                        $data['namaUnit'] = $item['value'];
                        break;
                    case 'Status':
                        $data['status'] = $item['value'];
                        break;
                }
            }
        }
        return $data;
    }

    private function normalizePetugasNames($petugas)
    {
        $replacements = [
            'Adi' => 'Adika', 'Adika Wicaksana' => 'Adika', 'Adikaka' => 'Adika',
            'adikaka' => 'Adika', 'dika' => 'Adika', 'Dika' => 'Adika',
            'dikq' => 'Adika', 'Dikq' => 'Adika', 'AAdika' => 'Adika',
            'virgie' => 'Virgie', 'Vi' => 'Virgie', 'vi' => 'Virgie',
            'Virgie Dika' => 'Virgie, Adika', 'Virgie dikq' => 'Virgie, Adika',
        ];

        $petugasList = preg_split('/\s*[,&]\s*|\s+dan\s+/i', $petugas);
        $normalizedList = array_map(fn($name) => $replacements[trim($name)] ?? trim($name), $petugasList);

        return implode(', ', array_unique($normalizedList));
    }

    private function normalizeUnitNames($unit)
    {
        return preg_match('/\b(?:poli\s*mata(?:\s*[\w\s]*)?)\b/i', $unit) ? 'Poli Mata' : ucfirst(strtolower($unit));
    }

    private function getPetugasCounts($processedData)
    {
        $petugasCounts = array_fill_keys(['Ganang', 'Agus', 'Ali Muhson', 'Virgie', 'Bayu', 'Adika'], 0);

        foreach ($processedData as $data) {
            $petugasList = array_unique(explode(', ', $data['Nama Petugas']));
            foreach ($petugasList as $petugas) {
                if (isset($petugasCounts[$petugas])) {
                    $petugasCounts[$petugas]++;
                }
            }
        }

        return array_filter($petugasCounts);
    }

    private function getStatusCounts($processedData)
    {
        $statusCounts = ['pending' => 0, 'Selesai' => 0];

        foreach ($processedData as $data) {
            if ($data['is_pending']) {
                $statusCounts[$data['status'] === 'Selesai' ? 'Selesai' : 'pending']++;
            } else {
                $statusCounts[$data['status']] = ($statusCounts[$data['status']] ?? 0) + 1;
            }
        }

        return $statusCounts;
    }

    private function getUnitCounts($processedData)
    {
        $unitCounts = array_fill_keys(['Non-Klinis', 'Klinis', 'Lainnya'], []);
        $statuses = ['Terkirim', 'Dalam Pengerjaan / Pengecekan Petugas', 'Selesai', 'Pending'];

        foreach ($processedData as $data) {
            $unitName = strtolower($data['Nama Unit/Poli']);
            $status = $data['is_pending'] ? ($data['status'] === 'Selesai' ? 'Selesai' : 'Pending') : $data['status'];

            $matched = false;
            foreach ($this->categories as $category => $units) {
                foreach ($units as $unit => $keywords) {
                    if ($this->matchUnit($unitName, $keywords)) {
                        if (!isset($unitCounts[$category][$unit])) {
                            $unitCounts[$category][$unit] = array_fill_keys($statuses, 0);
                        }
                        $unitCounts[$category][$unit][$status]++;
                        $matched = true;
                        break 2;
                    }
                }
            }

            if (!$matched) {
                if (!isset($unitCounts['Lainnya']['Lainnya'])) {
                    $unitCounts['Lainnya']['Lainnya'] = array_fill_keys($statuses, 0);
                }
                $unitCounts['Lainnya']['Lainnya'][$status]++;
            }
        }

        return $unitCounts;
    }

    private function matchUnit($unitName, $keywords)
    {
        foreach ($keywords as $keyword) {
            if ($keyword === '\brm\b') {
                if (preg_match('/\brm\b/i', $unitName)) return true;
            } elseif (stripos($unitName, $keyword) !== false) {
                return true;
            }
        }
        return false;
    }

    private function formatDateTime($dateTime)
    {
        return $dateTime instanceof Carbon ? $dateTime->toDateTimeString() : $dateTime;
    }

    private function calculateResponseTime($datetimeMasuk, $datetimeSelesai)
    {
        if (!$datetimeMasuk || !$datetimeSelesai) {
            return ['minutes' => null, 'formatted' => 'N/A'];
        }

        $diffInMinutes = Carbon::parse($datetimeMasuk)->diffInMinutes(Carbon::parse($datetimeSelesai));

        return [
            'minutes' => $diffInMinutes,
            'formatted' => $this->formatMinutes($diffInMinutes)
        ];
    }

    private function calculateAverageResponseTime($processedData)
    {
        $validResponseTimes = array_filter(array_column($processedData, 'respon_time_minutes'));
        $averageMinutes = count($validResponseTimes) > 0 ? array_sum($validResponseTimes) / count($validResponseTimes) : 0;

        return [
            'minutes' => round($averageMinutes, 2),
            'formatted' => $this->formatMinutes(round($averageMinutes))
        ];
    }

    private function calculateAverageCompletedResponseTime($processedData)
    {
        $completedResponseTimes = array_filter($processedData, fn($data) => $data['status'] === 'Selesai' && $data['respon_time_minutes'] !== null);
        $totalResponseTime = array_sum(array_column($completedResponseTimes, 'respon_time_minutes'));
        $countCompleted = count($completedResponseTimes);

        if ($countCompleted === 0) {
            return ['minutes' => 0, 'formatted' => 'N/A'];
        }

        $averageMinutes = $totalResponseTime / $countCompleted;

        return [
            'minutes' => round($averageMinutes, 2),
            'formatted' => $this->formatMinutes(round($averageMinutes))
        ];
    }

    private function formatMinutes($minutes)
    {
        $hours = floor($minutes / 60);
        $remainingMinutes = $minutes % 60;

        return $hours > 0 ? sprintf("%d jam %d menit", $hours, $remainingMinutes) : sprintf("%d menit", $remainingMinutes);
    }

    private function getAvailableMonths()
    {
        $months = Data::where('form_id', 3)
            ->selectRaw('DATE_FORMAT(created_at, "%Y-%m") as month')
            ->groupBy('month')
            ->orderBy('month', 'desc')
            ->pluck('month')
            ->toArray();

        return array_combine($months, array_map(fn($month) => Carbon::createFromFormat('Y-m', $month)->format('F Y'), $months));
    }

    public function getAvailableDates()
    {
        try {
            $dates = Data::selectRaw('DISTINCT YEAR(datetime_masuk) as year, MONTH(datetime_masuk) as month')
                ->orderBy('year', 'desc')
                ->orderBy('month', 'asc')
                ->get()
                ->map(fn($item) => sprintf('%04d-%02d', $item->year, $item->month));

            return response()->json($dates);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to retrieve available dates'], 500);
        }
    }
}