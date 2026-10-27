<?php

/**
 * Shop product entity.
 */
#[\AllowDynamicProperties]
class ShopEntity extends FpsEntity
{
    protected $id;
    protected $category_id;
    protected $title;
    protected $clean_url_title;
    protected $short_description;
    protected $description;
    protected $image;
    protected $active;
    protected $featured;
    protected $position;
    protected $views;
    protected $meta_title;
    protected $meta_description;
    protected $meta_keywords;
    protected $created_at;
    protected $updated_at;

    /**
     * Persist the product.
     *
     * @return int
     */
    public function save()
    {
        $params = array(
            'category_id' => (int)$this->category_id,
            'title' => (string)$this->title,
            'clean_url_title' => (string)$this->clean_url_title,
            'short_description' => (string)$this->short_description,
            'description' => (string)$this->description,
            'image' => (string)$this->image,
            'active' => !empty($this->active) ? 1 : 0,
            'featured' => !empty($this->featured) ? 1 : 0,
            'position' => (int)$this->position,
            'views' => (int)$this->views,
            'meta_title' => (string)$this->meta_title,
            'meta_description' => (string)$this->meta_description,
            'meta_keywords' => (string)$this->meta_keywords,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at ? $this->updated_at : date('Y-m-d H:i:s'),
        );

        if (!empty($this->id)) {
            $params['id'] = (int)$this->id;
        }

        return parent::save('shop_products', $params);
    }
}
