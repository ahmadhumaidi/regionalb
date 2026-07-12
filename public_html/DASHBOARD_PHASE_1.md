# Dashboard Overview RSM Phase 1

Phase 1 mengubah dashboard utama Regional B menjadi overview RSM berbasis database existing. Tidak ada tabel baru, tidak ada perubahan `config.php`, dan tidak ada deploy production.

## Nilai Aktual Database Staging

Pemeriksaan awal dilakukan pada database staging `staging_regionalb_db`:

- `rsm_reports.report_type`: `ads`
- `rsm_reports.status`: `Berjalan`
- `rsm_ad_leads.progress_status`: belum ada nilai
- `rsm_ad_leads.follow_up_result`: belum ada nilai
- `rsm_ad_leads.closing_status`: belum ada nilai

Karena `rsm_ad_leads` masih kosong, dashboard tetap membaca mapping status secara dinamis saat data detail lead tersedia.

## Mapping Status

- Follow Up dihitung dari detail lead yang `follow_up_result` atau `progress_status` terisi.
- Registrasi dihitung hanya dari nilai aktual `closing_status` yang mengandung makna daftar, registrasi, terdaftar, atau closing.
- Herregistrasi dihitung hanya dari nilai aktual `closing_status` yang eksplisit mengandung `herregistrasi`, `her registrasi`, atau `daftar ulang`.
- `Closing` tidak otomatis dianggap herregistrasi.

## Anti-Double-Count

Dashboard membuat agregat `rsm_ad_leads` per `report_id`, lalu join ke `rsm_reports`.

Aturan per laporan:

- Jika laporan punya detail di `rsm_ad_leads`, leads memakai jumlah detail lead.
- Jika laporan tidak punya detail di `rsm_ad_leads`, leads memakai `rsm_reports.leads_count`.
- Jika laporan punya detail lead, registrasi memakai mapping `closing_status` detail.
- Jika laporan tidak punya detail lead, registrasi memakai `rsm_reports.closing_count`.
- Herregistrasi hanya dihitung dari detail lead dengan status eksplisit herregistrasi.

Dengan pola ini, detail lead dan agregat laporan yang sama tidak dihitung ganda.

## Formula Anggaran

- Pengajuan = `SUM(budget_requested)`
- Disetujui = `SUM(budget_approved)`
- Spend = `SUM(realization_amount)`
- Sisa = Disetujui - Spend
- CPL = Spend / Leads iklan
- Cost per registrasi = Spend / Registrasi iklan
- Pembagian nol selalu menghasilkan `0`.

## Ranking Unit/Kampus

Ranking Top 10 memakai:

- `partner_campus_id` sebagai key utama.
- `unit_name` sebagai fallback jika `partner_campus_id` kosong.
- Urutan utama berdasarkan registrasi, lalu leads.

Kolom ranking:

- Leads
- Registrasi
- Herregistrasi
- Conversion rate
- Spend
- CPL
- Cost per registrasi

## Hak Akses

Query dashboard memakai scope existing `rsm_report_scope_sql()`:

- Senior melihat seluruh Regional B.
- Koordinator hanya wilayah dalam scope akun.
- Staff hanya data miliknya dan unit yang diizinkan.

## Filter Global

Filter tersedia untuk:

- Bulan PMB
- Rentang tanggal
- Wilayah
- Unit/kampus
- Staff
- Platform
- Status

Untuk staff, identitas wilayah, unit/kampus, dan nama staff ditampilkan terkunci sesuai akun login.

## Deployment Terpisah

Jangan merge ke `main` dan jangan deploy production sebelum validasi.

Rencana validasi staging:

1. Checkout branch `feature/rsm-dashboard-phase-1`.
2. Pastikan config database staging tersedia di lokasi config existing.
3. Buka dashboard sebagai senior, koordinator, dan staff.
4. Validasi panel Mapping Status Aktual.
5. Validasi laporan yang punya detail lead tidak double count.
6. Validasi laporan tanpa detail lead memakai fallback `leads_count` dan `closing_count`.
7. Validasi periode tanpa data dan kombinasi filter.
