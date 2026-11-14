<?php

declare(strict_types=1);

namespace Linkrot\Pages;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use League\CommonMark\Extension\CommonMark\Node\Inline\HtmlInline;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Node\Block\AbstractBlock;
use League\CommonMark\Node\Node;
use League\CommonMark\Parser\MarkdownParser;
use League\CommonMark\Renderer\HtmlRenderer;
use Linkrot\Custom\InvalidCustomFile;

/**
 * Markdown to the HTML of a page, strictly: no raw HTML at all (an upload that contains some is refused, and the
 * renderer drops it as well), no link or image with a scheme that can run code, no http images. Tables, strikethrough
 * and plain URLs that become links are on; headings are demoted by one level, because the title of the page is the h1.
 *
 * Raise VERSION whenever the output for the same Markdown can change (library update with different markup, a changed
 * rule here): the cached HTML of every page is made again.
 */
final class PageRenderer
{
    public const int VERSION = 1;

    /** How many problems an error message names. */
    private const int REPORTED = 5;

    private ?Environment $environment = null;

    /** @param string $host the host the page is served for: links to it are not external */
    public function __construct(private readonly string $host) {}

    /** Built on first use: most requests never render anything. */
    private function environment(): Environment
    {
        if ($this->environment instanceof Environment) {
            return $this->environment;
        }
        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
            'external_link' => [
                'internal_hosts' => $this->host,
                'nofollow' => 'external',
                'noopener' => 'external',
                'noreferrer' => 'external',
            ],
        ]);
        foreach ([new CommonMarkCoreExtension(), new TableExtension(), new StrikethroughExtension(), new AutolinkExtension(), new ExternalLinkExtension()] as $extension) {
            $environment->addExtension($extension);
        }

        return $this->environment = $environment;
    }

    /**
     * @throws InvalidCustomFile with the offending places
     */
    public function render(string $markdown): string
    {
        $document = new MarkdownParser($this->environment())->parse($markdown);

        $problems = [];
        foreach ($document->iterator() as $node) {
            $problem = $this->check($node);
            if ($problem !== null) {
                $problems[] = $problem;
            }
            if ($node instanceof Heading) {
                $node->setLevel(min(6, $node->getLevel() + 1));
            }
            if ($node instanceof Image) {
                $node->data->set('attributes/loading', 'lazy');
                $node->data->set('attributes/referrerpolicy', 'no-referrer');
            }
        }
        $problems = array_values(array_unique($problems));
        if ($problems !== []) {
            $more = count($problems) > self::REPORTED ? ' (and ' . (count($problems) - self::REPORTED) . ' more)' : '';
            throw new InvalidCustomFile(implode('; ', array_slice($problems, 0, self::REPORTED)) . $more);
        }

        return new HtmlRenderer($this->environment())->renderDocument($document)->getContent();
    }

    private function check(Node $node): ?string
    {
        if ($node instanceof HtmlBlock || $node instanceof HtmlInline) {
            return $this->where($node) . 'raw HTML is not allowed, use Markdown';
        }
        if ($node instanceof Link && !$this->linkAllowed($node->getUrl())) {
            return $this->where($node) . 'link to ' . $this->excerpt($node->getUrl()) . ' is not allowed (https, http, mailto, tel, or a path on this server)';
        }
        if ($node instanceof Image && !$this->imageAllowed($node->getUrl())) {
            return $this->where($node) . 'image ' . $this->excerpt($node->getUrl()) . ' is not allowed (https, a data:image/png, gif, jpeg or webp, or a path on this server)';
        }

        return null;
    }

    /** "line N: " of the block the node is in; an inline node has no line of its own. */
    private function where(Node $node): string
    {
        for ($at = $node; $at instanceof Node; $at = $at->parent()) {
            if ($at instanceof AbstractBlock && $at->getStartLine() !== null) {
                return 'line ' . $at->getStartLine() . ': ';
            }
        }

        return '';
    }

    private function scheme(string $url): ?string
    {
        return preg_match('~^([a-z][a-z0-9+.-]*):~i', trim($url), $m) === 1 ? strtolower($m[1]) : null;
    }

    private function linkAllowed(string $url): bool
    {
        $url = trim($url);
        if (str_starts_with($url, '//')) {
            return false;
        }

        return in_array($this->scheme($url), [null, 'https', 'http', 'mailto', 'tel'], true);
    }

    private function imageAllowed(string $url): bool
    {
        $url = trim($url);
        if (str_starts_with($url, '//')) {
            return false;
        }

        return match ($this->scheme($url)) {
            null => str_starts_with($url, '/'),
            'https' => true,
            'data' => preg_match('~^data:image/(png|gif|jpeg|webp);base64,[A-Za-z0-9+/=]+$~i', $url) === 1,
            default => false,
        };
    }

    private function excerpt(string $url): string
    {
        return '`' . (mb_strlen($url) > 40 ? mb_substr($url, 0, 40) . '...' : $url) . '`';
    }
}
