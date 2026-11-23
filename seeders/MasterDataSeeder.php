<?php

declare(strict_types=1);

namespace app\seeders;

use Yii;

/**
 * Seeder for Batch 2 Master Data tables:
 * departments, doctors, doctor_schedules, procedures, medicines, patients.
 * All data is dummy data for local assessment/demo only.
 */
class MasterDataSeeder
{
    /**
     * @return array<string, int>
     */
    public static function run(): array
    {
        $db = Yii::$app->db;
        $now = date('Y-m-d H:i:s');

        // Clear in FK dependency order
        $db->createCommand()->delete('{{%doctor_schedules}}')->execute();
        $db->createCommand()->delete('{{%doctors}}')->execute();
        $db->createCommand()->delete('{{%departments}}')->execute();
        $db->createCommand()->delete('{{%procedures}}')->execute();
        $db->createCommand()->delete('{{%medicines}}')->execute();
        $db->createCommand()->delete('{{%patients}}')->execute();

        // 1. Departments (Poliklinik)
        $departments = [
            [1, 'POLI-UMUM', 'Poliklinik Umum', 35000, 'Pelayanan pemeriksaan medis umum rawat jalan', true, 1, 1, $now, $now],
            [2, 'POLI-GIGI', 'Poliklinik Gigi & Mulut', 50000, 'Pelayanan kesehatan gigi dan mulut', true, 1, 1, $now, $now],
            [3, 'POLI-ANAK', 'Poliklinik Anak', 65000, 'Pelayanan kesehatan bayi dan anak (pediatri)', true, 1, 1, $now, $now],
            [4, 'POLI-PD', 'Poliklinik Penyakit Dalam', 75000, 'Pelayanan konsultasi dan penanganan penyakit dalam', true, 1, 1, $now, $now],
        ];
        $deptCount = $db->createCommand()->batchInsert('{{%departments}}', [
            'id', 'code', 'name', 'registration_fee', 'description', 'is_active',
            'created_by', 'updated_by', 'created_at', 'updated_at',
        ], $departments)->execute();

        // 2. Doctors (User ID 3 = doctor@example.com from UserSeeder)
        $doctorUserExists = (bool) $db->createCommand('SELECT 1 FROM {{%users}} WHERE id = 3')->queryScalar();
        $doctors = [
            [1, $doctorUserExists ? 3 : null, 1, 'SIP-001/DU/2026', 'dr. Andi Pratama', 'Dokter Umum', '081200010001', 50000, true, 1, 1, $now, $now],
            [2, null, 2, 'SIP-002/DG/2026', 'drg. Melati Kusuma', 'Dokter Gigi', '081200010002', 75000, true, 1, 1, $now, $now],
            [3, null, 3, 'SIP-003/SPA/2026', 'dr. Hendra Wijaya, Sp.A', 'Spesialis Anak', '081200010003', 120000, true, 1, 1, $now, $now],
            [4, null, 4, 'SIP-004/SPPD/2026', 'dr. Ratna Sari, Sp.PD', 'Spesialis Penyakit Dalam', '081200010004', 135000, true, 1, 1, $now, $now],
        ];
        $doctorCount = $db->createCommand()->batchInsert('{{%doctors}}', [
            'id', 'user_id', 'department_id', 'sip_number', 'full_name', 'specialization', 'phone',
            'consultation_fee', 'is_active', 'created_by', 'updated_by', 'created_at', 'updated_at',
        ], $doctors)->execute();

        // 3. Doctor Schedules (0=Minggu, 1=Senin .. 6=Sabtu)
        $schedules = [
            // dr. Andi Pratama (Poli Umum) — Senin s/d Sabtu pagi
            [1, 1, 1, 1, '08:00:00', '14:00:00', 30, true, 1, 1, $now, $now],
            [2, 1, 1, 2, '08:00:00', '14:00:00', 30, true, 1, 1, $now, $now],
            [3, 1, 1, 3, '08:00:00', '14:00:00', 30, true, 1, 1, $now, $now],
            [4, 1, 1, 4, '08:00:00', '14:00:00', 30, true, 1, 1, $now, $now],
            [5, 1, 1, 5, '08:00:00', '12:00:00', 25, true, 1, 1, $now, $now],
            [6, 1, 1, 6, '08:00:00', '12:00:00', 20, true, 1, 1, $now, $now],
            // drg. Melati Kusuma (Poli Gigi) — Senin, Rabu, Jumat
            [7, 2, 2, 1, '09:00:00', '15:00:00', 15, true, 1, 1, $now, $now],
            [8, 2, 2, 3, '09:00:00', '15:00:00', 15, true, 1, 1, $now, $now],
            [9, 2, 2, 5, '09:00:00', '14:00:00', 15, true, 1, 1, $now, $now],
            // dr. Hendra Wijaya, Sp.A (Poli Anak) — Selasa, Kamis, Sabtu
            [10, 3, 3, 2, '08:30:00', '13:30:00', 20, true, 1, 1, $now, $now],
            [11, 3, 3, 4, '08:30:00', '13:30:00', 20, true, 1, 1, $now, $now],
            [12, 3, 3, 6, '09:00:00', '13:00:00', 20, true, 1, 1, $now, $now],
            // dr. Ratna Sari, Sp.PD (Poli Penyakit Dalam) — Senin, Selasa, Kamis
            [13, 4, 4, 1, '13:00:00', '17:00:00', 20, true, 1, 1, $now, $now],
            [14, 4, 4, 2, '13:00:00', '17:00:00', 20, true, 1, 1, $now, $now],
            [15, 4, 4, 4, '13:00:00', '17:00:00', 20, true, 1, 1, $now, $now],
        ];
        $scheduleCount = $db->createCommand()->batchInsert('{{%doctor_schedules}}', [
            'id', 'doctor_id', 'department_id', 'day_of_week', 'start_time', 'end_time',
            'max_quota', 'is_active', 'created_by', 'updated_by', 'created_at', 'updated_at',
        ], $schedules)->execute();

        // 4. Procedures (Tindakan & Tarif Medis)
        $procedures = [
            [1, 'TND-KONSUL-UMUM', 'Konsultasi Dokter Umum', 'consultation', 50000, 'Pemeriksaan fisik dan konsultasi dokter umum', true, 1, 1, $now, $now],
            [2, 'TND-KONSUL-SP', 'Konsultasi Dokter Spesialis', 'consultation', 125000, 'Pemeriksaan dan konsultasi dokter spesialis', true, 1, 1, $now, $now],
            [3, 'TND-NEBULIZER', 'Terapi Nebulizer', 'action', 65000, 'Tindakan terapi uap saluran pernapasan', true, 1, 1, $now, $now],
            [4, 'TND-WOUND-CARE', 'Perawatan Luka Ringan', 'action', 75000, 'Pembersihan luka dan penggantian perban steril', true, 1, 1, $now, $now],
            [5, 'TND-SCALING', 'Scaling Gigi', 'action', 200000, 'Pembersihan karang gigi rahang atas dan bawah', true, 1, 1, $now, $now],
            [6, 'TND-EKG', 'Pemeriksaan EKG 12 Lead', 'diagnostic', 110000, 'Elektrokardiografi jantung istirahat', true, 1, 1, $now, $now],
            [7, 'LAB-DL', 'Pemeriksaan Darah Lengkap (CBC)', 'laboratory', 95000, 'Hematologi rutin lengkap (Hb, Leukosit, Trombosit, Hematokrit)', true, 1, 1, $now, $now],
            [8, 'LAB-GDS', 'Pemeriksaan Gula Darah Sewaktu', 'laboratory', 40000, 'Pengukuran kadar glukosa darah sewaktu', true, 1, 1, $now, $now],
            [9, 'RAD-CT-HEAD', 'CT-Scan Kepala (Non-Kontras)', 'diagnostic', 1250000, 'Pemeriksaan CT-Scan kepala axial/coronal/sagittal via gateway eksternal CT-Scan', true, 1, 1, $now, $now],
            [10, 'RAD-CT-THORAX', 'CT-Scan Thorax (Dengan Kontras)', 'diagnostic', 1850000, 'Pemeriksaan CT-Scan thorax kontras IV resolusi tinggi via gateway eksternal CT-Scan', true, 1, 1, $now, $now],
            [11, 'RAD-XRAY-THORAX', 'Foto Rontgen X-Ray Thorax PA', 'diagnostic', 180000, 'Radiografi konvensional dada proyeksi PA via gateway X-Ray/PACS', true, 1, 1, $now, $now],
            [12, 'RAD-MRI-BRAIN', 'MRI Kepala / Brain (1.5T)', 'diagnostic', 2750000, 'Pencitraan resonansi magnetik otak tanpa radiasi pengion via gateway MRI', true, 1, 1, $now, $now],
            [13, 'RAD-USG-ABD', 'USG Abdomen Lengkap', 'diagnostic', 350000, 'Ultrasonografi organ abdomen (hepar, kandung empedu, ginjal, limpa) via gateway USG', true, 1, 1, $now, $now],
        ];
        $procedureCount = $db->createCommand()->batchInsert('{{%procedures}}', [
            'id', 'code', 'name', 'category', 'tariff', 'description', 'is_active',
            'created_by', 'updated_by', 'created_at', 'updated_at',
        ], $procedures)->execute();

        // 5. Medicines (Obat, Stok & Harga)
        $medicines = [
            [1, 'OBT-PCM500', 'Paracetamol 500mg', 'tablet', 200, 30, 1500, true, 1, 1, $now, $now],
            [2, 'OBT-AMX500', 'Amoxicillin 500mg', 'kapsul', 150, 25, 3000, true, 1, 1, $now, $now],
            [3, 'OBT-CTM4', 'Chlorpheniramine Maleate 4mg', 'tablet', 180, 20, 1000, true, 1, 1, $now, $now],
            [4, 'OBT-OMZ20', 'Omeprazole 20mg', 'kapsul', 120, 20, 4500, true, 1, 1, $now, $now],
            [5, 'OBT-IBU400', 'Ibuprofen 400mg', 'tablet', 140, 20, 2500, true, 1, 1, $now, $now],
            [6, 'OBT-OBH100', 'OBH Combi Sirup 100ml', 'botol', 45, 10, 18500, true, 1, 1, $now, $now],
            [7, 'OBT-SALB2', 'Salbutamol 2mg', 'tablet', 90, 15, 2000, true, 1, 1, $now, $now],
            [8, 'OBT-VITC500', 'Vitamin C 500mg', 'tablet', 250, 30, 1800, true, 1, 1, $now, $now],
            [9, 'OBT-CET10', 'Cetirizine 10mg', 'tablet', 8, 15, 3500, true, 1, 1, $now, $now],
            [10, 'OBT-INS-TEST', 'Amlodipine 5mg (Stok Kritis Demo)', 'tablet', 1, 10, 4000, true, 1, 1, $now, $now],
        ];
        $medicineCount = $db->createCommand()->batchInsert('{{%medicines}}', [
            'id', 'code', 'name', 'unit', 'stock', 'min_stock', 'unit_price', 'is_active',
            'created_by', 'updated_by', 'created_at', 'updated_at',
        ], $medicines)->execute();

        // 6. Patients (Dummy Pasien)
        $patients = [
            [1, 'RM-202611-0001', '3273010101900001', 'Ahmad Fauzi', '1990-01-01', 'male', '081311110001', 'Jl. Merdeka No. 12, Bandung', 'O', true, 1, 1, $now, $now],
            [2, 'RM-202611-0002', '3273014505920002', 'Rina Marlina', '1992-05-05', 'female', '081311110002', 'Jl. Asia Afrika No. 45, Bandung', 'A', true, 1, 1, $now, $now],
            [3, 'RM-202611-0003', '3273011208850003', 'Bambang Suryanto', '1985-08-12', 'male', '081311110003', 'Jl. Braga No. 88, Bandung', 'B', true, 1, 1, $now, $now],
            [4, 'RM-202611-0004', '3273016011180004', 'Naila Putri Azzahra', '2018-11-20', 'female', '081311110004', 'Jl. Dago No. 21, Bandung', 'AB', true, 1, 1, $now, $now],
            [5, 'RM-202611-0005', '3273012203780005', 'Hendra Gunawan', '1978-03-22', 'male', '081311110005', 'Jl. Buah Batu No. 102, Bandung', 'O', true, 1, 1, $now, $now],
        ];
        $patientCount = $db->createCommand()->batchInsert('{{%patients}}', [
            'id', 'medical_record_number', 'national_id', 'full_name', 'birth_date', 'gender',
            'phone', 'address', 'blood_type', 'is_active', 'created_by', 'updated_by', 'created_at', 'updated_at',
        ], $patients)->execute();

        // Sync PostgreSQL sequences
        foreach (['departments', 'doctors', 'doctor_schedules', 'procedures', 'medicines', 'patients'] as $table) {
            $db->createCommand("SELECT setval(pg_get_serial_sequence('{$table}', 'id'), COALESCE((SELECT MAX(id) FROM {$table}), 1));")->execute();
        }

        return [
            'departments' => $deptCount,
            'doctors' => $doctorCount,
            'doctor_schedules' => $scheduleCount,
            'procedures' => $procedureCount,
            'medicines' => $medicineCount,
            'patients' => $patientCount,
        ];
    }
}
