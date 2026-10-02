<?php

namespace App\Repository;

use App\Entity\BayesTraining;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BayesTraining>
 */
class BayesTrainingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BayesTraining::class);
    }

    /**
     * Get word frequencies for a specific classification
     *
     * @return array<string, int>
     */
    public function getWordFrequenciesForClass(string $classification): array
    {
        /** @var list<array{word: mixed, frequency: mixed}> $results */
        $results = $this->createQueryBuilder('bt')
            ->select('bt.word', 'bt.frequency')
            ->where('bt.classification = :classification')
            ->setParameter('classification', $classification)
            ->getQuery()
            ->getResult();

        $frequencies = [];
        foreach ($results as $row) {
            $word = $row['word'];
            $frequency = $row['frequency'];
            if (is_string($word) && is_numeric($frequency)) {
                $frequencies[$word] = (int) $frequency;
            }
        }

        return $frequencies;
    }

    /**
     * Get all word frequencies grouped by classification.
     * Model shape: classification => (word => frequency).
     *
     * @return array<string, array<string, int>>
     */
    public function getAllWordFrequencies(): array
    {
        /** @var list<array{classification: mixed, word: mixed, frequency: mixed}> $results */
        $results = $this->createQueryBuilder('bt')
            ->select('bt.classification', 'bt.word', 'bt.frequency')
            ->getQuery()
            ->getResult();

        $model = [];
        foreach ($results as $row) {
            $class = $row['classification'];
            $word = $row['word'];
            $frequency = $row['frequency'];
            if (!is_string($class) || !is_string($word) || !is_numeric($frequency)) {
                continue;
            }
            if (!isset($model[$class])) {
                $model[$class] = [];
            }
            $model[$class][$word] = (int) $frequency;
        }

        return $model;
    }

    /**
     * Update or create word frequency
     */
    public function updateWordFrequency(string $word, string $classification, int $increment = 1): BayesTraining
    {
        $word = strtolower(trim($word));
        
        $existing = $this->findOneBy([
            'word' => $word,
            'classification' => $classification,
        ]);

        if ($existing) {
            $existing->incrementFrequency($increment);
            $this->getEntityManager()->persist($existing);
        } else {
            $existing = new BayesTraining();
            $existing->setWord($word);
            $existing->setClassification($classification);
            $existing->setFrequency($increment);
            $this->getEntityManager()->persist($existing);
        }

        return $existing;
    }

    /**
     * Batch update word frequencies from text
     */
    public function learnFromText(string $text, string $classification): int
    {
        $text = strtolower($text);
        preg_match_all('/\b[a-z\']+\b/', $text, $matches);
        $words = $matches[0];

        $stopWords = ['the', 'a', 'an', 'is', 'are', 'was', 'were', 'be', 'been', 
                      'being', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 
                      'would', 'could', 'should', 'may', 'might', 'must', 'shall',
                      'to', 'of', 'in', 'for', 'on', 'with', 'at', 'by', 'from',
                      'up', 'about', 'into', 'through', 'during', 'before', 'after',
                      'above', 'below', 'between', 'under', 'again', 'further',
                      'then', 'once', 'here', 'there', 'when', 'where', 'why', 'how',
                      'all', 'each', 'few', 'more', 'most', 'other', 'some', 'such',
                      'only', 'own', 'same', 'so', 'than', 'too', 'very', 's', 't',
                      'can', 'just', 'now', 'or', 'and', 'but', 'if', 'i', 'me', 'my',
                      'myself', 'we', 'our', 'ours', 'ourselves', 'you', 'your', 'yours',
                      'yourself', 'yourselves', 'he', 'him', 'his', 'himself', 'she',
                      'her', 'hers', 'herself', 'it', 'its', 'itself', 'they', 'them',
                      'their', 'theirs', 'themselves', 'what', 'which', 'who', 'whom'];

        $words = array_filter($words, fn($w) => !in_array($w, $stopWords) && strlen($w) > 2);
        
        $wordCounts = array_count_values($words);

        $em = $this->getEntityManager();
        /** @var list<BayesTraining> $existingRecords */
        $existingRecords = $this->createQueryBuilder('bt')
            ->where('bt.classification = :classification')
            ->andWhere('bt.word IN (:words)')
            ->setParameter('classification', $classification)
            ->setParameter('words', array_keys($wordCounts))
            ->getQuery()
            ->getResult();

        $existingIndex = [];
        foreach ($existingRecords as $record) {
            $word = $record->getWord();
            if ($word !== null) {
                $existingIndex[$word] = $record;
            }
        }

        $updated = 0;
        foreach ($wordCounts as $word => $count) {
            if (isset($existingIndex[$word])) {
                $existingIndex[$word]->incrementFrequency($count);
                $em->persist($existingIndex[$word]);
            } else {
                $entry = new BayesTraining();
                $entry->setWord($word);
                $entry->setClassification($classification);
                $entry->setFrequency($count);
                $em->persist($entry);
            }
            $updated++;
        }

        $em->flush();

        return $updated;
    }

    /**
     * Get total word count for a classification
     */
    public function getTotalWordsForClass(string $classification): int
    {
        $result = $this->createQueryBuilder('bt')
            ->select('SUM(bt.frequency)')
            ->where('bt.classification = :classification')
            ->setParameter('classification', $classification)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) ($result ?? 0);
    }

    /**
     * Get vocabulary size for a classification
     */
    public function getVocabularySizeForClass(string $classification): int
    {
        return $this->count(['classification' => $classification]);
    }

    /**
     * Get all unique classifications
     *
     * @return list<string>
     */
    public function getClassifications(): array
    {
        /** @var list<array{classification: mixed}> $result */
        $result = $this->createQueryBuilder('bt')
            ->select('DISTINCT bt.classification')
            ->getQuery()
            ->getResult();

        return array_map(
            static fn ($row) => is_string($row['classification']) ? $row['classification'] : '',
            $result
        );
    }

    /**
     * Get model statistics
     *
     * @return array<string, array{vocabulary_size: int, total_words: int}>
     */
    public function getModelStats(): array
    {
        $classifications = $this->getClassifications();
        $stats = [];

        foreach ($classifications as $class) {
            $stats[$class] = [
                'vocabulary_size' => $this->getVocabularySizeForClass($class),
                'total_words' => $this->getTotalWordsForClass($class),
            ];
        }

        return $stats;
    }
}
