# Gamification Roadmap Regional B

Tujuan gamification adalah membuat staff marketing merasa progresnya terlihat, kompetisi terasa sehat, dan aktivitas penting seperti follow up, registrasi, herregistrasi, laporan harian, dan efisiensi iklan menjadi kebiasaan.

Gamification tidak boleh mendorong input asal banyak. Poin harus lebih menghargai kualitas, validasi, dan hasil nyata daripada sekadar jumlah data.

## Prinsip Utama

1. Data tetap berasal dari database existing terlebih dahulu.
2. Tidak mengubah definisi KPI Phase 1.
3. Tidak menghitung data yang belum valid sebagai pencapaian final.
4. Staff dibandingkan dalam scope yang adil: wilayah, unit/kampus, atau role yang relevan.
5. Leads bernilai kecil jika tidak ada follow up.
6. Registrasi dan herregistrasi bernilai jauh lebih tinggi daripada leads.
7. Poin final sebaiknya dihitung dari laporan yang sudah diverifikasi/disetujui.
8. Leaderboard harus memberi semangat, bukan mempermalukan staff.

## Modul Gamification

### 1. Leaderboard Staff

Leaderboard menampilkan ranking staff dalam periode tertentu.

Filter:

- Harian
- Mingguan
- Bulanan
- Wilayah
- Unit/kampus
- Staff

Metrik:

- Total poin
- Leads valid
- Follow up
- Registrasi
- Herregistrasi
- Laporan harian lengkap
- Efisiensi biaya

Tampilan awal:

```text
Top Performer Bulan Ini
1. Staff A - 320 pts
2. Staff B - 285 pts
3. Staff C - 260 pts
```

### 2. Point System

Poin awal dihitung dari data existing.

| Aktivitas | Sumber Data | Poin |
|---|---|---:|
| Laporan harian dibuat | `rsm_reports` | 5 |
| Laporan disetujui | `rsm_reports.status` | 10 |
| Lead masuk | `rsm_reports.leads_count` atau `rsm_ad_leads` | 2 |
| Lead sudah follow up | `follow_up_result` / `progress_status` | 4 |
| Registrasi | `closing_status` atau `closing_count` | 20 |
| Herregistrasi | `closing_status` eksplisit herregistrasi | 35 |
| Upload data hasil iklan | `rsm_ad_leads.report_id` | 10 |
| Aktivitas dengan kendala dan rencana jelas | `obstacle_text` + `follow_up_text` | 5 |

Catatan:

- Jika laporan punya detail `rsm_ad_leads`, poin lead dan registrasi dihitung dari detail.
- Jika laporan tidak punya detail, fallback ke `rsm_reports.leads_count` dan `closing_count`.
- Ini mengikuti aturan anti-double-count Phase 1.

### 3. Badge Achievement

Badge awal bisa dihitung dari query tanpa tabel baru.

| Badge | Syarat Awal |
|---|---|
| Follow Up Hero | Follow up terbanyak dalam periode |
| Closing Hunter | Registrasi terbanyak dalam periode |
| Herregistrasi Champion | Herregistrasi terbanyak dalam periode |
| Consistency Streak | Laporan harian rutin beberapa hari berturut-turut |
| Fast Reporter | Laporan masuk konsisten setiap hari |
| Budget Efficient | CPL atau cost per registrasi terbaik |
| Campus Champion | Top performer pada unit/kampus tertentu |
| Regional Booster | Top performer per wilayah |

### 4. Streak

Streak mendorong kebiasaan laporan harian.

Contoh:

- 3 hari berturut-turut input laporan.
- 7 hari berturut-turut input laporan.
- 14 hari berturut-turut input laporan.

Data awal:

- `rsm_reports.report_date`
- `rsm_reports.staff_name`
- `rsm_reports.created_by_name`

### 5. Challenge Mingguan/Bulanan

Challenge membuat target terasa seperti misi.

Contoh:

```text
Challenge Minggu Ini
- 50 follow up valid
- 10 registrasi
- 100% laporan harian masuk
- CPL di bawah target internal
```

Phase awal challenge bisa tampil statis dari konfigurasi kode. Setelah stabil, challenge bisa dibuat dinamis lewat tabel.

## Fairness Rules

Aturan keadilan penting agar kompetisi sehat.

1. Staff hanya dibandingkan dengan scope yang relevan.
2. Leaderboard umum boleh ada, tapi ranking wilayah/unit harus lebih dominan.
3. Leads tanpa follow up tidak boleh terlalu tinggi nilainya.
4. Registrasi dan herregistrasi harus punya bobot terbesar.
5. Data draft boleh tampil sebagai progres, tapi poin final masuk setelah verifikasi/disetujui.
6. Senior dan koordinator tidak ikut ranking staff, kecuali ada leaderboard khusus manajemen.
7. Jika unit/kampus punya volume berbeda jauh, tampilkan juga conversion rate dan efisiensi biaya.

## Phase 1.5 - Gamification Tanpa Tabel Baru

Fitur yang bisa dibuat dari database existing:

- Leaderboard staff bulanan.
- Poin real-time dari `rsm_reports` dan `rsm_ad_leads`.
- Badge kalkulasi sederhana.
- Streak laporan harian.
- Ranking wilayah/unit berbasis poin.

Tidak perlu tabel baru.

Keterbatasan:

- Riwayat poin tidak tersimpan.
- Badge belum bisa dikunci permanen.
- Challenge belum bisa dikelola admin.
- Perubahan formula akan mengubah hasil historis karena dihitung ulang.

## Phase 2 - Gamification Persisten

Jika konsep sudah cocok, tambahkan tabel baru.

### rsm_game_point_rules

Untuk mengatur formula poin.

```text
id
code
label
points
is_active
created_at
updated_at
```

### rsm_game_point_logs

Untuk menyimpan riwayat poin.

```text
id
user_id
report_id
ad_lead_id
rule_code
points
period_date
note
created_at
```

### rsm_game_badges

Untuk daftar badge.

```text
id
code
label
description
icon
is_active
created_at
updated_at
```

### rsm_game_user_badges

Untuk badge yang sudah diraih staff.

```text
id
user_id
badge_id
period_month
awarded_at
```

### rsm_game_challenges

Untuk challenge mingguan/bulanan.

```text
id
title
description
scope_area
scope_regional
start_date
end_date
target_metric
target_value
reward_text
is_active
created_at
updated_at
```

## Rekomendasi UI

Tambahkan satu section di dashboard overview:

```text
Gamification
├── Top 3 Performer
├── Poin Saya Bulan Ini
├── Badge Terbaru
├── Streak Laporan
└── Challenge Aktif
```

Tambahkan menu terpisah setelah stabil:

```text
Leaderboard
├── Staff
├── Unit/Kampus
├── Wilayah
└── Badge
```

## Formula Poin Awal

Formula awal yang disarankan:

```text
total_points =
  laporan_harian * 5
  + laporan_disetujui * 10
  + leads * 2
  + follow_up * 4
  + registrasi * 20
  + herregistrasi * 35
  + upload_data_iklan * 10
```

Tambahan opsional:

```text
bonus_efisiensi =
  +20 jika cost per registrasi masuk Top 3 terbaik
  +10 jika CPL masuk Top 3 terbaik
```

## Catatan Anti-Manipulasi

1. Poin final hanya untuk laporan yang sudah diverifikasi/disetujui.
2. Leads tanpa nomor kontak atau tanpa follow up tidak boleh bernilai tinggi.
3. Edit data setelah penilaian harus tercatat di log.
4. Senior perlu melihat anomali: leads tinggi tapi follow up kosong, spend tinggi tapi registrasi nol, atau laporan harian copy-paste.
5. Sistem harus menampilkan metrik kualitas, bukan hanya jumlah.

## Urutan Implementasi yang Disarankan

1. Tambahkan panel kecil "Top Performer Bulan Ini" di dashboard overview.
2. Tambahkan helper query `rsm_gamification_summary()`.
3. Tambahkan tabel leaderboard staff tanpa tabel baru.
4. Tambahkan badge kalkulasi sederhana.
5. Setelah user suka, baru buat tabel point logs dan badge permanen.

## Output yang Diharapkan

Staff melihat progresnya sendiri.

Koordinator melihat siapa yang perlu dibantu.

Senior Manager melihat performa sehat per staff, wilayah, dan unit/kampus.

Kompetisi terasa menyenangkan karena ada poin, badge, dan challenge, tetapi tetap berbasis data yang valid.
