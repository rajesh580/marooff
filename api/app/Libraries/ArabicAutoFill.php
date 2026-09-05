<?php

namespace App\Libraries;

/**
 * Glue between admin saves and the Translator. Idempotent + cheap:
 *
 *   • Builds an MD5 of the relevant English source fields.
 *   • If that hash matches what we stored last time → does nothing (no API call).
 *   • Otherwise calls the Translator for each field, writes the *_ar columns,
 *     and updates ar_hash so we won't translate again until English changes.
 *
 * A failing translation never breaks the save: the field stays NULL and we
 * leave ar_hash unchanged so a future save retries it.
 *
 * Usage in a controller:
 *   ArabicAutoFill::run(
 *     table:    'products',
 *     id:       $productId,
 *     fields:   ['name' => 'name_ar', 'short_desc' => 'short_desc_ar', ...],
 *   );
 *
 * Some tables have hand-curated overrides for short, ambiguous words
 * (e.g. category "Face" → "وجه" not "واجهة"). Pass them via $overrides.
 */
class ArabicAutoFill
{
    /**
     * @param string             $table     Table name.
     * @param int                $id        Row id.
     * @param array<string,string> $fields  Map: english_column => arabic_column.
     * @param array<string,string> $overrides Optional: english_value => forced_arabic.
     */
    public static function run(string $table, int $id, array $fields, array $overrides = []): void
    {
        $db = \Config\Database::connect();
        $row = $db->table($table)->where('id', $id)->get()->getRowArray();
        if (!$row) return;

        // Compose English source for hashing — concat in a stable order.
        $sourceParts = [];
        foreach (array_keys($fields) as $col) {
            $sourceParts[] = (string) ($row[$col] ?? '');
        }
        $newHash = md5(implode("\x1f", $sourceParts));
        if (!empty($row['ar_hash']) && $row['ar_hash'] === $newHash) {
            return; // nothing changed, no work
        }

        $translator = new Translator();
        $patch      = ['ar_hash' => $newHash];
        $allOk      = true;
        foreach ($fields as $enCol => $arCol) {
            $src = trim((string) ($row[$enCol] ?? ''));
            if ($src === '') { $patch[$arCol] = null; continue; }
            $forced = $overrides[mb_strtolower($src, 'UTF-8')] ?? null;
            if ($forced !== null) { $patch[$arCol] = $forced; continue; }
            $arabic = $translator->en2ar($src);
            if ($arabic === null) { $allOk = false; continue; }
            $patch[$arCol] = $arabic;
        }
        // If every field failed, don't even update — we want a clean retry next save.
        if (count($patch) <= 1) return;

        // If anything failed, don't bake the hash — retry later.
        if (!$allOk) unset($patch['ar_hash']);

        $db->table($table)->where('id', $id)->update($patch);
    }

    /**
     * Same idea but for the settings key/value table.
     * Each English key gets translated into a sibling `<key>_ar` row.
     *
     * @param array<string> $keys English keys.
     */
    public static function runSettings(array $keys): void
    {
        $db   = \Config\Database::connect();
        $rows = $db->table('settings')->whereIn('key', $keys)->get()->getResultArray();
        if (!$rows) return;
        $byKey = [];
        foreach ($rows as $r) $byKey[$r['key']] = (string) ($r['value'] ?? '');

        // Hash all the values together so we only translate when at least one changes.
        $newHash = md5(implode("\x1f", array_map(fn($k) => $byKey[$k] ?? '', $keys)));
        $hashKey = 'ar_hash:settings';
        $oldHash = $db->table('settings')->where('key', $hashKey)->get()->getRowArray()['value'] ?? '';
        if ($oldHash === $newHash) return;

        $translator = new Translator();
        $allOk      = true;
        foreach ($keys as $k) {
            $src = trim($byKey[$k] ?? '');
            $arKey = "{$k}_ar";
            if ($src === '') {
                $db->table('settings')->where('key', $arKey)->update(['value' => '']);
                continue;
            }
            $ar = $translator->en2ar($src);
            if ($ar === null) { $allOk = false; continue; }

            $exists = $db->table('settings')->where('key', $arKey)->countAllResults() > 0;
            if ($exists) {
                $db->table('settings')->where('key', $arKey)->update(['value' => $ar]);
            } else {
                $db->table('settings')->insert(['key' => $arKey, 'value' => $ar]);
            }
        }
        if (!$allOk) return;

        // Store the hash.
        $existsHash = $db->table('settings')->where('key', $hashKey)->countAllResults() > 0;
        if ($existsHash) {
            $db->table('settings')->where('key', $hashKey)->update(['value' => $newHash]);
        } else {
            $db->table('settings')->insert(['key' => $hashKey, 'value' => $newHash]);
        }
    }
}
