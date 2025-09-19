<?php
namespace Merlin\ImageRemover\Model\Service\Config;

use Magento\Framework\App\ResourceConnection;

class ConfigExtractor
{
    /** @var ResourceConnection */
    private $resource;

    public function __construct(ResourceConnection $resource)
    {
        $this->resource = $resource;
    }

    public function collect(): array
    {
        $conn = $this->resource->getConnection();
        $cfg = $this->resource->getTableName('core_config_data');

        $paths = [
            'design/header/logo_src',
            'design/header/logo_src_small',
            'design/email/logo',
            'sales/identity/logo',
            'design/head/shortcut_icon',
        ];

        $in = implode(',', array_map(function($p){ return "'" . addslashes($p) . "'"; }, $paths));
        $rows = $conn->fetchCol("SELECT value FROM `{$cfg}` WHERE path IN ({$in}) AND value IS NOT NULL AND value != ''");

        $fallback = $conn->fetchCol("SELECT value FROM `{$cfg}` WHERE (path LIKE '%logo%' OR path LIKE '%favicon%') AND value IS NOT NULL AND value != ''");

        return array_values(array_unique(array_merge($rows, $fallback)));
    }
}
