-- =============================================================================
-- Afet İşleri Md. — activity_catalog_id = 109 için 1. ve 2. haftayı ayırma
-- Önce SELECT'leri çalıştırın, sonra UPDATE bloğunu (backup sonrası) uygulayın.
-- =============================================================================

-- 1) Afet müdürlüğü kullanıcı id'si
SELECT id, name
FROM users
WHERE name LIKE '%Afet İşleri%'
   OR name LIKE '%Afet%Risk%';

-- 2) Bu müdürlüğün raporlarında katalog 109 geçen kayıtlar
SELECT
    af.id   AS rapor_id,
    af.user_id,
    u.name  AS mudurluk,
    af.yil,
    af.ay,
    jt.row_idx,
    jt.hafta,
    jt.faaliyet_kodu,
    jt.activity_catalog_id
FROM aylik_faaliyets af
JOIN users u ON u.id = af.user_id
JOIN JSON_TABLE(
    af.faaliyetler,
    '$[*]' COLUMNS (
        row_idx FOR ORDINALITY,
        activity_catalog_id VARCHAR(32) PATH '$.activity_catalog_id',
        faaliyet_kodu VARCHAR(64) PATH '$.faaliyet_kodu',
        hafta VARCHAR(32) PATH '$.hafta'
    )
) AS jt
WHERE u.name LIKE '%Afet%'
  AND (
        jt.activity_catalog_id = '109'
     OR jt.activity_catalog_id = 109
  )
ORDER BY af.yil DESC, af.ay DESC, af.id, jt.row_idx;

-- 3) Aynı satırda haftalık kayıtlarda hem 1 hem 2 görünenler (birleşik görünüm)
SELECT
    af.id AS rapor_id,
    af.yil,
    af.ay,
    jt.row_idx,
    jt.hafta AS satir_hafta,
    GROUP_CONCAT(DISTINCT hk.kayit_hafta ORDER BY hk.kayit_hafta) AS kayit_haftalari
FROM aylik_faaliyets af
JOIN users u ON u.id = af.user_id
JOIN JSON_TABLE(
    af.faaliyetler,
    '$[*]' COLUMNS (
        row_idx FOR ORDINALITY,
        activity_catalog_id VARCHAR(32) PATH '$.activity_catalog_id',
        hafta VARCHAR(32) PATH '$.hafta',
        NESTED PATH '$.kapsam_verileri[*].haftalik_kayitlar[*]' COLUMNS (
            kayit_hafta VARCHAR(32) PATH '$.hafta'
        )
    )
) AS jt
LEFT JOIN JSON_TABLE(
    af.faaliyetler,
    CONCAT('$[', jt.row_idx - 1, '].kapsam_verileri[*].haftalik_kayitlar[*]')
    COLUMNS (
        kayit_hafta VARCHAR(32) PATH '$.hafta'
    )
) AS hk ON TRUE
WHERE u.name LIKE '%Afet%'
  AND (jt.activity_catalog_id = '109' OR jt.activity_catalog_id = 109)
GROUP BY af.id, af.yil, af.ay, jt.row_idx, jt.hafta
HAVING FIND_IN_SET('1', kayit_haftalari) AND FIND_IN_SET('2', kayit_haftalari);

-- =============================================================================
-- NOT: MySQL'de JSON dizi elemanını güvenli şekilde 2 satıra bölmek zordur.
-- Aşağıdaki PHP script'i sunucuda çalıştırın (önerilen yol):
--
--   php artisan tinker --execute="require base_path('scripts/split_afet_catalog_109_weeks.php');"
--
-- veya:
--   php scripts/split_afet_catalog_109_weeks.php
-- =============================================================================
