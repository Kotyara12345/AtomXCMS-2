<?php

/**
 * Shop product model.
 *
 * The module uses the CMS database layer directly for the catalogue and
 * checkout because those queries need joins and aggregates. This model is
 * kept for the standard module contract and for clean URL lookups.
 */
#[\AllowDynamicProperties]
class ShopModel extends FpsModel
{
    public $Table = 'shop_products';

    /**
     * Resolve a human-like product URL to a product id.
     *
     * @param string $title
     * @return int
     */
    public function getIdByHluTitle($title)
    {
        $title = trim((string)$title);
        if ($title === '') return 0;

        $DB = $this->getDbDriver();
        $table = $DB->getFullTableName($this->Table);
        $title = $DB->escape($title);
        $rows = $DB->query("SELECT `id` FROM `{$table}` WHERE `clean_url_title` = '{$title}' LIMIT 1");

        return !empty($rows[0]['id']) ? (int)$rows[0]['id'] : 0;
    }
}
