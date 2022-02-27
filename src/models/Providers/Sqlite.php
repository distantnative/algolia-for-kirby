<?php

namespace Kirby\Search\Providers;

use Kirby\Search\Index;
use Kirby\Search\Provider;

use Kirby\Database\Database;
use Kirby\Toolkit\Dir;

/**
 * Sqlite provider
 *
 * @author Nico Hoffmann <nico@getkirby.com>
 * @license MIT
 * @link https://getkirby.com
 */
class Sqlite extends Provider
{

    /**
     * Additional characters to be considered
     * as part of tokens
     *
     * @var string
     */
    protected static $tokenize = '@?!-_&:';

    /**
     * Constructor
     *
     * @param \Kirby\Search\Index $search
     */
    public function __construct(Index $index)
    {
        $this->setOptions($index);

        // Create root directory
        $root = $this->options['file'];

        if (is_callable($root) === true) {
            $root = call_user_func($root);
        }

        $dir = dirname($root);

        if (file_exists($dir) === false) {
            Dir::make($dir);
        }

        // Connect to sqlite database
        $this->store = new Database([
            'type'     => 'sqlite',
            'database' => $root
        ]);
    }

    /**
     * Default options for Sqlite provider
     *
     * @return array
     */
    protected function defaults(): array
    {
        return [
            'file'     => kirby()->root('logs') . '/search/index.sqlite',
            'fuzzy'    => true,
            'operator' => 'OR'
        ];
    }

    /**
     * Checks if an active index is already present
     *
     * @return bool
     */
    public function hasIndex(): bool
    {
        return $this->store->validateTable('models') === true;
    }

    /**
     * Create FTS5 based virtual table and insert
     * all objects
     *
     * @param array $data
     * @return void
     */
    public function replace(array $data): void
    {
        // Get all field names for columns to be created
        $columns = $this->toColumns($data);
        $columns[] = 'id UNINDEXED';
        $columns[] = '_type UNINDEXED';

        // Drop and create fresh virtual table
        $this->store->execute('DROP TABLE IF EXISTS models');
        $this->store->execute(
            'CREATE VIRTUAL TABLE models USING FTS5(' . $this->store->escape(implode(',', $columns)) . ', tokenize="unicode61 remove_diacritics 2 tokenchars \'' . static::$tokenize . '\'");'
        );
        // Insert each object into the table
        foreach ($data as $entry) {
            $this->insert($entry);
        }
    }

    /**
     * Run search query against database index
     *
     * @param string $query
     * @param array $options
     * @param \Kirby\Cms\Collection|null $collection
     *
     * @return array
     */
    public function search(string $query, array $options, $collection = null): array
    {
        // Remove punctuation from query
        $query = preg_replace('/[^a-z0-9äöüÄÖÜß]+/i', ' ', $query);

        // Generate options with defaults
        $options = array_merge($this->options, $options);

        // Define pagination data
        $page   = $options['page'];
        $offset = ($options['page'] - 1) * $options['limit'];
        $limit  = $options['limit'];

        // Construct query based on tokens:
        // split query along whitespace
        preg_match_all(
            '/[\pL\pN\pPd]+/u',
            $query,
            $tokens
        );
        $tokens = $tokens[0];

        // check if query already contains qualified operators
        $qualified = in_array('AND', $tokens) ||
            in_array('OR', $tokens) ||
            in_array('NOT', $tokens);

        // append * to all tokens except operators
        $tokens = array_map(function ($token) {
            return in_array($token, ['AND', 'OR', 'NOT']) ? $token : $token . '*';
        }, $tokens);

        // merge query again, if unqualified insert operator (default OR)
        $query = implode($qualified ? ' ' : (' ' . $options['operator'] . ' '), $tokens);

        // get matches from database
        try {
            $data = $this->store->models()
                ->select('id, _type')
                ->where('models MATCH \'' . $this->store->escape($query) . '\'');
        } catch (\Exception $error) {
            return [];
        }


        // Custom weights for ranking
        if (is_array($this->options['weights'] ?? null) === true) {

            // Get all columns from table
            $columns = $this->store->query('PRAGMA table_info(models);')->toArray();

            // Match columns to custom weights
            $weights = array_map(function ($column) {
                return $this->options['weights'][$column->name()] ?? 1;
            }, $columns);

            // Add Sqlite clause to weigh ranking
            $weights = implode(', ', $weights);
            $data    = $data->andWhere('rank MATCH \'bm25(' . $weights . ')\'');
        }

        // Fetch all data as array
        // with limit and offset
        $data = $data
            ->order('rank')
            ->offset($offset)
            ->limit($limit)
            ->fetch('array')->all();

        // If no matches found
        if ($data === false) {
            return [];
        }

        return [
            'hits'  => $data->toArray(),
            'page'  => $page,
            'total' => $data->count(),
            'limit' => $limit
        ];
    }

    public function insert(array $object): void
    {
        if ($this->options['fuzzy'] !== false) {
            $object = $this->toFuzzy($object);
        }

        $this->store->models()->insert($object);
    }

    public function delete(string $id): void
    {
        $this->store->models()->delete(['id' => $id]);
    }

    /**
     * Returns array of field names for models array
     *
     * @param array $data
     * @return array
     */
    protected function toColumns(array $data): array
    {
        $fields = array_merge(...$data);

        // Remove unsearchable fields
        unset($fields['id'], $fields['_type']);

        return array_keys($fields);
    }

    /**
     * Creates value representing each state of the fields'
     * string where you take away the first letter.
     * Needed for lookups in the middle or end of text.
     *
     * @param array $data
     *
     * @return array
     */
    protected function toFuzzy(array $data): array
    {
        foreach ($data as $field => $value) {
            // Don't fuzzify unsearchable fields
            if ($field === 'id' || $field === '_type') {
                continue;
            }

            // Make sure to only fuzzify fields according to config
            if (
                $this->options['fuzzy'] !== true &&
                in_array($field, $this->options['fuzzy'][$data['_type']] ?? []) === false
            ) {
                continue;
            }

            // Add original string to the beginning
            $data[$field] = $value;

            // Split into words/tokens
            preg_match_all(
                '/[\pL\pN\pPd]+/u',
                $value,
                $words
            );
            $words = $words[0];

            // Foreach token
            foreach ($words as $word) {
                while (mb_strlen($word) > 0) {
                    // Remove first character and add to value,
                    // then repeat until the end of the word
                    $word = mb_substr($word, 1);
                    $data[$field] .= ' ' . $word;
                }
            }
        }

        return $data;
    }
}
