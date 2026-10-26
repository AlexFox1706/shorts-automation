<?php
declare(strict_types=1);

namespace ShortsFactory;

/** История тем: защита от повторов и ротация рубрик. */
final class TopicHistory
{
    public function __construct(private Database $db, private float $threshold = 0.6) {}

    /** @return list<array{topic_key:string,title:string}> */
    public function recent(int $limit = 120): array
    {
        return $this->db->all('SELECT topic_key, title FROM topic_history ORDER BY id DESC LIMIT ' . max(1, $limit));
    }

    /** Возвращает описание совпавшей темы или null. */
    public function duplicateOf(string $topicKey, string $title): ?string
    {
        $k = Text::tokens($topicKey);
        $t = Text::tokens($title);
        foreach ($this->recent(500) as $row) {
            if (Text::norm($row['topic_key']) === Text::norm($topicKey)) {
                return $row['title'];
            }
            if (Text::jaccard($k, Text::tokens($row['topic_key'])) >= $this->threshold
                || Text::jaccard($t, Text::tokens($row['title'])) >= $this->threshold) {
                return $row['title'];
            }
        }
        return null;
    }

    public function record(int $videoId, string $topicKey, string $title, ?string $category): void
    {
        $this->db->exec(
            'INSERT INTO topic_history (video_id, topic_key, title, category, outcome, created_at) VALUES (?, ?, ?, ?, \'generated\', ?)',
            [$videoId, $topicKey, $title, $category, Clock::now()]
        );
    }

    public function markPublished(int $videoId): void
    {
        $this->db->exec("UPDATE topic_history SET outcome = 'published' WHERE video_id = ? AND outcome = 'generated'", [$videoId]);
    }

    /** Рубрика, которой не было дольше всего. @param list<string> $categories */
    public function pickCategory(array $categories, string $seed): string
    {
        $last = [];
        foreach ($this->db->all('SELECT category, MAX(id) m FROM topic_history WHERE category IS NOT NULL GROUP BY category') as $r) {
            $last[$r['category']] = (int)$r['m'];
        }
        $best = [];
        $min = PHP_INT_MAX;
        foreach ($categories as $c) {
            $m = $last[$c] ?? 0;
            if ($m < $min) {
                $min = $m;
                $best = [$c];
            } elseif ($m === $min) {
                $best[] = $c;
            }
        }
        return $best[crc32($seed) % count($best)];
    }
}
