<?php
namespace Merlin\ImageRemover\Model\Service;

use Magento\Framework\App\ResourceConnection;

class DbScanner
{
    /** @var ResourceConnection */
    private $resource;

    public function __construct(ResourceConnection $resource)
    {
        $this->resource = $resource;
    }

    public function scan(bool $intensive = true): array
    {
        $conn = $this->resource->getConnection();
        $tables = $conn->fetchCol('SHOW TABLES');
        $refs = [];

        $scanTypes = ['char','varchar','text','tinytext','mediumtext','longtext','json'];

        foreach ($tables as $table) {
            try {
                $columns = $conn->fetchAll("SHOW FULL COLUMNS FROM `{$table}`");
            } catch (\Throwable $e) {
                continue;
            }

            $textCols = [];
            foreach ($columns as $col) {
                $type = strtolower((string)($col['Type'] ?? ''));
                $field = (string)($col['Field'] ?? '');
                if ($field === '') continue;
                if (preg_match('#^([a-z]+)#i', $type, $m)) {
                    $base = strtolower($m[1]);
                } else {
                    $base = $type;
                }
                if (in_array($base, $scanTypes, true)) {
                    $textCols[] = $field;
                }
            }
            if (!$textCols) continue;

            $likes = [
                "`%COL%` LIKE '%/media/%'",
                "`%COL%` LIKE '%{{media %'",
                "`%COL%` LIKE '%wysiwyg/%'",
                "`%COL%` LIKE '%amasty/%'",
                "`%COL%` LIKE '%.png%'",
                "`%COL%` LIKE '%.jpg%'",
                "`%COL%` LIKE '%.jpeg%'",
                "`%COL%` LIKE '%.gif%'",
                "`%COL%` LIKE '%.webp%'",
                "`%COL%` LIKE '%.svg%'",
                "`%COL%` LIKE '%logo/%'",
                "`%COL%` LIKE '%logo/stores/%'",
                "`%COL%` LIKE '%favicon/%'",
                "`%COL%` LIKE '%catalog/product/%'",
                "`%COL%` LIKE '%attribute/swatch/%'",
                "`%COL%` LIKE '%category/%'",
                "`%COL%` LIKE '%background-image:%'",
                "`%COL%` LIKE '%\\\"src\\\":\\\"%'",
                "`%COL%` LIKE '%\\\"image\\\":\\\"%'",
                "`%COL%` LIKE '%url(%'"
            ];

            for ($i = 0; $i < count($textCols); $i++) {
                $c = $textCols[$i];
                $where = implode(' OR ', array_map(function($s) use ($c) {
                    return str_replace('%COL%', str_replace('`','``',$c), $s);
                }, $likes));

                $sql = "SELECT `{$c}` AS val FROM `{$table}` WHERE {$where}";
                try {
                    $stmt = $conn->query($sql);
                } catch (\Throwable $e) {
                    continue;
                }

                while (true) {
                    $row = $stmt->fetchColumn();
                    if ($row === false) break;
                    $val = (string)$row;
                    if ($val === '') continue;

                    $refs = array_merge($refs, $this->extractFromString($val));

                    if ($intensive) {
                        foreach ($this->decodeVariants($val) as $dv) {
                            $refs = array_merge($refs, $this->extractFromString($dv));
                            $refs = array_merge($refs, $this->extractFromJson($dv));
                            $refs = array_merge($refs, $this->extractFromSerialized($dv));
                        }
                    }
                }
            }
        }

        $uniq = [];
        foreach ($refs as $r) { $uniq[$r] = true; }
        return array_keys($uniq);
    }

    private function decodeVariants(string $val): array
    {
        $out = [$val];

        $d1 = urldecode($val);
        if ($d1 !== $val) { $out[] = $d1; }
        $d2 = urldecode($d1);
        if ($d2 !== $d1 && $d2 !== $val) { $out[] = $d2; }

        $e1 = html_entity_decode($val, ENT_QUOTES | ENT_HTML5);
        if ($e1 !== $val && !in_array($e1, $out, true)) { $out[] = $e1; }

        $ue = urldecode($e1);
        if ($ue !== $e1 && !in_array($ue, $out, true)) { $out[] = $ue; }

        return $out;
    }

    private function extractFromJson(string $str): array
    {
        $str = trim($str);
        if ($str === '' || (($str[0] !== '{') && ($str[0] !== '['))) return [];
        $data = json_decode($str, true);
        if (!is_array($data)) return [];
        $collected = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveArrayIterator($data));
        foreach ($it as $v) {
            if (is_string($v)) {
                $collected = array_merge($collected, $this->extractFromString($v));
            }
        }
        return $collected;
    }

    private function extractFromSerialized(string $str): array
    {
        if (!preg_match('#^(a|s|O|C|b|i|d):#', ltrim($str))) return [];
        $result = @unserialize($str, ['allowed_classes' => false]);
        if ($result === false && $str !== 'b:0;') return [];
        $values = [];
        $this->collectStrings($result, $values);
        $refs = [];
        foreach ($values as $v) {
            $refs = array_merge($refs, $this->extractFromString($v));
        }
        return $refs;
    }

    private function collectStrings($data, array &$out): void
    {
        if (is_string($data)) {
            $out[] = $data;
            return;
        }
        if (is_array($data)) {
            foreach ($data as $k => $v) {
                if (is_string($k)) $out[] = $k;
                $this->collectStrings($v, $out);
            }
        }
    }

    private function extractFromString(string $content): array
    {
        $refs = [];

        if (preg_match_all('#https?://[^"\']*/media/[^"\')\s]+#i', $content, $m1)) {
            foreach ($m1[0] as $u) { $refs[] = $u; }
        }
        if (preg_match_all('#(?:^|[^a-z0-9_/])(/?media/[^"\')\s]+)#i', $content, $m1b)) {
            foreach ($m1b[1] as $u) { $refs[] = $u; }
        }
        if (preg_match_all('#\{\{\s*media\s+url\s*=\s*["\']([^"\']+)["\']\s*\}\}#i', $content, $m2)) {
            foreach ($m2[1] as $u) { $refs[] = 'media/' . ltrim($u, '/'); }
        }

        if (preg_match_all('#\b((?:catalog/product|wysiwyg|logo|favicon|captcha|attribute/swatch|category|downloadable|email|theme|amasty|pagebuilder)/[A-Za-z0-9_\-./]+?\.(?:png|jpe?g|gif|webp|svg|bmp|tiff))\b#i', $content, $m3)) {
            foreach ($m3[1] as $u) { $refs[] = $u; }
        }

        if (preg_match_all('#url\(([^)]+)\)#i', $content, $m4)) {
            foreach ($m4[1] as $u) {
                $u = trim($u, " '\"");
                if ($u !== '') $refs[] = $u;
            }
        }

        return $refs;
    }
}
