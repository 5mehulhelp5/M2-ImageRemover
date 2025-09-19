<?php
namespace Merlin\ImageRemover\Model\Service\Integration;

use Magento\Framework\App\ResourceConnection;

class AmastyMegaMenuExtractor
{
    /** @var ResourceConnection */
    private $resource;

    public function __construct(ResourceConnection $resource)
    {
        $this->resource = $resource;
    }

    public function collect(): array
    {
        $out = [];
        $out = array_merge($out, $this->scanAmastyTables());
        $out = array_merge($out, $this->scanCoreConfig());
        $out = array_merge($out, $this->scanCategoryEav());
        return array_values(array_unique($out));
    }

    private function scanAmastyTables(): array
    {
        $conn = $this->resource->getConnection();
        $tables = $conn->fetchCol('SHOW TABLES');
        $targets = [];
        foreach ($tables as $t) {
            $lt = strtolower($t);
            if ((strpos($lt, 'amasty') !== false) and (strpos($lt, 'mega') !== false || strpos($lt, 'menu') !== false)) {
                $targets[] = $t;
            }
        }
        if (!$targets) return [];

        $scanTypes = ['char','varchar','text','tinytext','mediumtext','longtext','json'];
        $likes = [
            "`%COL%` LIKE '%/media/%'",
            "`%COL%` LIKE '%{{media %'",
            "`%COL%` LIKE '%wysiwyg/%'",
            "`%COL%` LIKE '%.png%'",
            "`%COL%` LIKE '%.jpg%'",
            "`%COL%` LIKE '%.jpeg%'",
            "`%COL%` LIKE '%.gif%'",
            "`%COL%` LIKE '%.webp%'",
            "`%COL%` LIKE '%.svg%'",
            "`%COL%` LIKE '%logo/%'",
            "`%COL%` LIKE '%favicon/%'",
            "`%COL%` LIKE '%catalog/product/%'",
            "`%COL%` LIKE '%attribute/swatch/%'",
            "`%COL%` LIKE '%background-image:%'",
            "`%COL%` LIKE '%url(%'",
            "`%COL%` LIKE '%\\\"src\\\":\\\"%'",
            "`%COL%` LIKE '%\\\"image\\\":\\\"%'"
        ];

        $refs = [];
        foreach ($targets as $table) {
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

            foreach ($textCols as $c) {
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
                }
            }
        }
        return array_values(array_unique($refs));
    }

    private function scanCoreConfig(): array
    {
        $conn = $this->resource->getConnection();
        $table = $this->resource->getTableName('core_config_data');
        $rows = $conn->fetchAll("SELECT path, value FROM `{$table}` WHERE path LIKE 'amasty_megamenu/%' OR path LIKE '%/megamenu/%' OR path LIKE '%/mega_menu/%'");
        $refs = [];
        foreach ($rows as $r) {
            $p = (string)$r['path'];
            $v = (string)$r['value'];
            $refs = array_merge($refs, $this->extractFromString($p));
            $refs = array_merge($refs, $this->extractFromString($v));
        }
        return array_values(array_unique($refs));
    }

    private function scanCategoryEav(): array
    {
        $conn = $this->resource->getConnection();
        $eavAttr = $this->resource->getTableName('eav_attribute');
        $entityType = $this->resource->getTableName('eav_entity_type');
        $catEntityVarchar = $this->resource->getTableName('catalog_category_entity_varchar');
        $catEntityText = $this->resource->getTableName('catalog_category_entity_text');

        $entityTypeId = (int)$conn->fetchOne("SELECT entity_type_id FROM `{$entityType}` WHERE entity_type_code = 'catalog_category'");
        if (!$entityTypeId) return [];

        $attrIds = $conn->fetchCol("SELECT attribute_id FROM `{$eavAttr}` WHERE entity_type_id = {$entityTypeId} AND (attribute_code LIKE '%menu%' OR attribute_code LIKE '%mega%')");

        if (!$attrIds) return [];
        $in = implode(',', array_map('intval', $attrIds));

        $vals = [];
        $vals = array_merge($vals, $conn->fetchCol("SELECT value FROM `{$catEntityVarchar}` WHERE attribute_id IN ({$in}) AND value IS NOT NULL AND value != ''"));
        $vals = array_merge($vals, $conn->fetchCol("SELECT value FROM `{$catEntityText}` WHERE attribute_id IN ({$in}) AND value IS NOT NULL AND value != ''"));

        $refs = [];
        foreach ($vals as $v) {
            $refs = array_merge($refs, $this->extractFromString((string)$v));
        }
        return array_values(array_unique($refs));
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
        if (preg_match_all('#\b((?:catalog/product|wysiwyg|logo|favicon|captcha|attribute/swatch|category|downloadable|email|theme|amasty)/[A-Za-z0-9_\-./]+?\.(?:png|jpe?g|gif|webp|svg|bmp|tiff))\b#i', $content, $m3)) {
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
