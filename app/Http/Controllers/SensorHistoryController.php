<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Controllers\NotificationController;
use App\Services\AESDecryptor;
use App\Models\SensorHistory;
use App\Models\Device;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SensorHistoryController extends Controller
{
    /**
     * Menyimpan data history sensor.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */

    protected $NotificationController;

    public function __construct(NotificationController $NotificationController)
    {
        $this->NotificationController = $NotificationController;
    }

    public function index(Request $request)
    {
        $devices = Device::all();

        if ($devices->isEmpty()) {
            return redirect()->route('device.create');
        }

        $today = Carbon::today()->format('Y-m-d');

        // Ambil filter dari request atau gunakan nilai default
        $start_date = $request->input('start_date', $today);
        $end_date = $request->input('end_date', $today);
        $device_id = $request->input('device_id');

        // Query data berdasarkan filter
        $query = SensorHistory::query();

        if ($start_date && $end_date) {
            $query->whereBetween('recorded_at', [$start_date . ' 00:00:00', $end_date . ' 23:59:59']);
        }

        if ($device_id) {
            $query->where('device_id', $device_id);
        }

        // Urutkan data dari yang terbaru
        $sensorHistory = $query->orderBy('recorded_at', 'desc')->get();


        return view('pages.sensorHistory.index', compact('sensorHistory', 'devices', 'start_date', 'end_date', 'device_id'));
    }

    public function store(Request $request)
    {
        try {
            // Ambil data terenkripsi dari request
            $encryptedData = $request->getContent();

            if (!$encryptedData) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Encrypted data is required.',
                ], 400);
            }

            // Dekripsi data menggunakan layanan AESDecryptor
            $decryptor = new AESDecryptor();
            $decryptedData = $decryptor->decrypt($encryptedData);

            if (!is_array($decryptedData)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Decrypted data is not valid JSON.',
                ], 400);
            }

            // Log data hasil dekripsi
            // Log::info('data history perangkat setelah dekripsi', [
            //     'decrypted_data' => $decryptedData,
            // ]);

            // Validasi data setelah dekripsi
            $validator = Validator::make($decryptedData, [
                'device_id' => 'required',
                'temperature' => 'nullable|numeric',
                'humidity' => 'nullable|numeric',
                'smoke' => 'nullable|numeric',
                'motion' => 'nullable|boolean',
            ]);


            // Jika validator gagal
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validasi gagal',
                    'errors' => $validator->errors(),
                ], 400);
            }

            // Cek apakah device_id ada di tabel devices
            $deviceExists = Device::find($decryptedData['device_id']);

            // Jika device_id tidak ada, hanya jalankan sendFirebase
            if (!$deviceExists) {
                // Kirim data ke Firebase
                $this->sendFirebase($decryptedData);
                return response()->json([
                    'success' => true,
                    'message' => 'Device tidak ditemukan, namun data berhasil dikirim',
                ], 200);
            }

            // Kirim data ke Firebase
            $this->sendFirebase($decryptedData);

            // Cek threshold sensor
            $this->cekThresholdSensor($decryptedData, $deviceExists->device_name); 

            // Simpan data ke dalam sensor_histories
            $sensorHistory = SensorHistory::create([
                'device_id' => $decryptedData['device_id'],
                'temperature' => $decryptedData['temperature'],
                'humidity' => $decryptedData['humidity'],
                'smoke' => $decryptedData['smoke'],
                'motion' => $decryptedData['motion'],
                'recorded_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Data berhasil disimpan',
                'data' => $sensorHistory,
            ], 200);
        } catch (Exception $e) {
            // Tangani kesalahan dekripsi atau lainnya
            Log::error('Gagal menyimpan data: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal memproses data: ' . $e->getMessage(),
            ], 400);
        }
    }

    public function sendFirebase($data)
    {
        // Data yang akan dikirim ke Firebase
        $dataSensor = [
            'device_id' => $data['device_id'],
            'temperature' => $data['temperature'],
            'humidity' => $data['humidity'],
            'smoke' => $data['smoke'],
            'motion' => $data['motion'] ? 1 : 0, // Convert boolean to 0 or 1
            //'recorded_at' => now()->toDateTimeString(), // Menggunakan format yang sesuai Firebase
            'recorded_at' => now(), // Menggunakan format yang sesuai Firebase
        ];

        // Kirim data ke Firebase menggunakan HTTP Client Laravel
        $response = Http::patch('https://simover-kominfo-default-rtdb.asia-southeast1.firebasedatabase.app/' . $data['device_id'] . '/sensors.json', $dataSensor);
        //$response = Http::put('https://simover-kominfo-default-rtdb.asia-southeast1.firebasedatabase.app/1000000001', $dataSensor);

        if ($response->successful()) {
            return response()->json([
                'success' => true,
                'message' => 'Data berhasil disimpan dan dikirim ke Firebase',
                'data' => $data,
            ], 200);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengirim data ke Firebase',
                'errors' => $response->json(),
            ], 500);
        }
    }

    private function cekThresholdSensor($data, $device_name)
    {
        // // Ambil threshold dari Firebase
        $firebaseUrl = 'https://simover-kominfo-default-rtdb.asia-southeast1.firebasedatabase.app/' . $data['device_id'] . '/thresholds';
        $thresholds = [
            'asap' => $this->getThresholdFromFirebase($firebaseUrl . '/asap.json'),
            'kelembapan' => $this->getThresholdFromFirebase($firebaseUrl . '/kelembapan.json'),
            'suhu' => $this->getThresholdFromFirebase($firebaseUrl . '/suhu.json'),
        ];

        // Kirim notifikasi jika nilai melebihi threshold
        if ($data['smoke'] > $thresholds['asap']) {
            $this->NotificationController->sendNotificationToTopic('Peringatan ' . $device_name, 'Smoke level is above threshold');
        }
        if ($data['humidity'] > $thresholds['kelembapan']) {
            $this->NotificationController->sendNotificationToTopic('Peringatan ' . $device_name, 'Humidity level is above threshold');
        }
        if ($data['temperature'] > $thresholds['suhu']) {
            $this->NotificationController->sendNotificationToTopic('Peringatan ' . $device_name, 'Temperature is above threshold');
        }
    }

    private function getThresholdFromFirebase($url)
    {
        $response = Http::get($url);

        return $response->successful() ? $response->json() : null;
    }

    public function getData(Request $request)
    {
        // Validasi input device_id
        $request->validate([
            'device_id' => 'required|exists:devices,id',
        ]);

        // Ambil 10 data sensor history terbaru berdasarkan device_id
        $historySensors = SensorHistory::where('device_id', $request->device_id)
            ->orderByDesc('recorded_at')
            ->take(5)
            ->get();

        // Jika data ditemukan, kembalikan respons JSON
        if ($historySensors->isNotEmpty()) {
            return response()->json($historySensors);
        }

        return response()->json([
            'success' => false,
            'message' => 'Data sensor tidak ditemukan.'
        ], 404);
    }





    // public function store(Request $request)
    // {
    //     // Validasi data yang diterima
    //     $validator = Validator::make($request->all(), [
    //         'device_id' => 'required',
    //         'temperature' => 'nullable|numeric',
    //         'humidity' => 'nullable|numeric',
    //         'smoke' => 'nullable|numeric',
    //         'motion' => 'nullable|boolean',
    //     ]);

    //     // Log data request
    //     Log::info('data history perangkat', [
    //         'request_data' => $request->all(),
    //     ]);

    //     // Cek apakah device_id ada di tabel devices
    //     $deviceExists = Device::find($request->device_id);

    //     // Jika device_id tidak ada, hanya jalankan sendFirebase
    //     if (!$deviceExists) {
    //         $this->sendFirebase($request);
    //         return response()->json([
    //             'success' => true,
    //             'message' => 'Device tidak ditemukan, namun data berhasil dikirim',
    //         ], 200);
    //     }


    //     $this->sendFirebase($request);

    //     // Jika validator gagal
    //     if ($validator->fails()) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Validasi gagal',
    //             'errors' => $validator->errors(),
    //         ], 400);
    //     }

    //     // Cek threshold sensor
    //     $this->cekThresholdSensor($request);

    //     // Simpan data ke dalam sensor_histories
    //     $sensorHistory = SensorHistory::create([
    //         'device_id' => $request->device_id,
    //         'temperature' => $request->temperature,
    //         'humidity' => $request->humidity,
    //         'smoke' => $request->smoke,
    //         'motion' => $request->motion,
    //         'recorded_at' => now(),
    //     ]);

    //     return response()->json([
    //         'success' => true,
    //         'message' => 'Data berhasil disimpan',
    //         'data' => $sensorHistory,
    //     ], 200);
    // }


    // public function index(Request $request)
    // {
    //     $devices = Device::all();

    //     // Filter data by selected device if applicable
    //     $query = SensorHistory::with('device');

    //     if ($request->device_id) {
    //         $query->where('device_id', $request->device_id);
    //     }

    //     $sensorHistory = $query->latest('recorded_at')->get();

    //     return view('pages.riwayat', compact('sensorHistory', 'devices'));
    // }

    // public function index(Request $request)
    // {
    //     $devices = Device::all();

    //     // Filter data by selected device if applicable
    //     $query = SensorHistory::with('device');

    //     // Filter berdasarkan device
    //     if ($request->has('device_id') && $request->device_id !== 'all') {
    //         $query->where('device_id', $request->device_id);
    //     }

    //     // Filter berdasarkan rentang waktu
    //     $timeRange = $request->get('time_range', 'all');
    //     $currentDate = SupportCarbon::now();

    //     if ($timeRange == 'today') {
    //         $query->whereDate('recorded_at', $currentDate->toDateString());
    //     } elseif ($timeRange == 'week') {
    //         $query->whereBetween('recorded_at', [
    //             $currentDate->startOfWeek()->toDateString(),
    //             $currentDate->endOfWeek()->toDateString(),
    //         ]);
    //     } elseif ($timeRange == 'month') {
    //         $query->whereMonth('recorded_at', $currentDate->month)
    //               ->whereYear('recorded_at', $currentDate->year);
    //     } elseif ($timeRange == 'year') {
    //         $query->whereYear('recorded_at', $currentDate->year);
    //     }

    //     // Ambil hasil query
    //     $sensorHistory = $query->get();

    //     // Kembalikan view dengan data yang sudah difilter
    //     return view('pages.riwayat', compact('sensorHistory', 'devices'));
    // }


    // public function getData()
    // {
    //     // Validasi input device_id
    //     // $request->validate([
    //     //     'device_id' => 'required|exists:devices,id',
    //     // ]);

    //     // Ambil 10 data sensor history terbaru berdasarkan device_id
    //     //$historySensors = SensorHistory::where('device_id', $request->device_id)
    //     $historySensors = SensorHistory::orderByDesc('recorded_at')
    //         ->take(5)
    //         ->get();

    //     // Jika data ditemukan, kembalikan respons JSON
    //     if ($historySensors->isNotEmpty()) {
    //         return response()->json($historySensors);
    //     }

    //     return response()->json([
    //         'success' => false,
    //         'message' => 'Data sensor tidak ditemukan.'
    //     ], 404);
    // }
}
