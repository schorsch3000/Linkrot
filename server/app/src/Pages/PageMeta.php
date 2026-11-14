<?php

declare(strict_types=1);

namespace Linkrot\Pages;

/** What the frontmatter of a page says (defaults filled in) and what the index keeps per page. */
final readonly class PageMeta
{
    public function __construct(
        public string $slug,
        public string $title,
        /** Text of the link in the footer. */
        public string $link,
        public int $order,
        public bool $published,
        public string $lang,
        /** Version of the renderer that made the cached HTML. */
        public int $renderVersion,
        public int $updatedAt,
    ) {}

    /** @return array<string, string|int|bool> */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'title' => $this->title,
            'link' => $this->link,
            'order' => $this->order,
            'published' => $this->published,
            'lang' => $this->lang,
            'render_version' => $this->renderVersion,
            'updated_at' => $this->updatedAt,
        ];
    }

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): ?self
    {
        $slug = $data['slug'] ?? null;
        $title = $data['title'] ?? null;
        $link = $data['link'] ?? null;
        $order = $data['order'] ?? null;
        $published = $data['published'] ?? null;
        $lang = $data['lang'] ?? null;
        $version = $data['render_version'] ?? null;
        $updatedAt = $data['updated_at'] ?? null;
        if (!is_string($slug) || !is_string($title) || !is_string($link) || !is_int($order) || !is_bool($published)
            || !is_string($lang) || !is_int($version) || !is_int($updatedAt)) {
            return null;
        }

        return new self($slug, $title, $link, $order, $published, $lang, $version, $updatedAt);
    }
}
