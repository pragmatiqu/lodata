<?php

namespace Flat3\OData;

use Countable;
use Flat3\OData\Exception\Internal\LexerException;
use Flat3\OData\Exception\Internal\PathNotHandledException;
use Flat3\OData\Exception\Protocol\BadRequestException;
use Flat3\OData\Exception\Protocol\NotFoundException;
use Flat3\OData\Exception\Protocol\NotImplementedException;
use Flat3\OData\Expression\Lexer;
use Flat3\OData\Interfaces\EmitInterface;
use Flat3\OData\Interfaces\EntityTypeInterface;
use Flat3\OData\Interfaces\IdentifierInterface;
use Flat3\OData\Interfaces\PipeInterface;
use Flat3\OData\Interfaces\QueryOptions\PaginationInterface;
use Flat3\OData\Interfaces\ResourceInterface;
use Flat3\OData\Internal\ObjectArray;
use Flat3\OData\Property\Navigation;
use Flat3\OData\Property\Navigation\Binding;
use Flat3\OData\Request\Option;
use Flat3\OData\Traits\HasEntityType;
use Flat3\OData\Traits\HasIdentifier;
use Flat3\OData\Type\EntityType;
use Iterator;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

abstract class EntitySet implements EntityTypeInterface, IdentifierInterface, ResourceInterface, Iterator, Countable, EmitInterface, PipeInterface
{
    use HasIdentifier;
    use HasEntityType;

    /** @var ObjectArray $navigationBindings Navigation bindings */
    protected $navigationBindings;

    /** @var int $top Page size to return from the query */
    protected $top = PHP_INT_MAX;

    /** @var int $skip Skip value to use in the query */
    protected $skip = 0;

    /** @var int $topCounter Total number of records fetched for internal pagination */
    private $topCounter = 0;

    /** @var int Limit of number of records to evaluate from the source */
    protected $topLimit = PHP_INT_MAX;

    /** @var int $maxPageSize Maximum pagination size allowed for this entity set */
    protected $maxPageSize = 500;

    /** @var null|Entity[] $results Result set from the query */
    protected $results = null;

    /** @var Transaction $transaction */
    protected $transaction;

    /** @var Property $keyProperty */
    protected $keyProperty;

    /** @var Primitive $entityId */
    protected $entityId;

    /** @var bool $isInstance */
    protected $isInstance = false;

    public function __construct(string $identifier, EntityType $entityType)
    {
        $this->setIdentifier($identifier);

        $this->type = $entityType;
        $this->navigationBindings = new ObjectArray();
    }

    public function __clone()
    {
        $this->isInstance = true;
    }

    public static function factory(string $identifier, EntityType $entityType): self
    {
        return new static($identifier, $entityType);
    }

    public function asInstance(Transaction $transaction): self
    {
        if ($this->isInstance) {
            throw new RuntimeException('Attempted to clone an instance of an entity set');
        }

        $set = clone $this;
        $set->transaction = $transaction;
        return $set;
    }

    public function setKey(Primitive $key): self
    {
        $this->keyProperty = $key->getProperty();
        $this->entityId = $key;
        return $this;
    }

    public function getKind(): string
    {
        return 'EntitySet';
    }

    /**
     * The current entity
     *
     * @return Entity
     */
    public function current(): ?Entity
    {
        if (null === $this->results && !$this->valid()) {
            return null;
        }

        return current($this->results);
    }

    /**
     * Move to the next result
     */
    public function next(): void
    {
        next($this->results);
    }

    public function key()
    {
        $entity = $this->current();

        if (!$entity) {
            return null;
        }

        return $entity->getEntityId();
    }

    public function rewind()
    {
        throw new RuntimeException('Entity sets cannot be rewound');
    }

    public function count()
    {
        $this->valid();
        return $this->results ? count($this->results) : null;
    }

    /**
     * Whether there is a current entity in the result set
     * Implements internal pagination
     *
     * @return bool
     */
    public function valid(): bool
    {
        if (0 === $this->top) {
            return false;
        }

        if ($this->results === null) {
            $this->results = $this->generate();
            $this->topCounter = count($this->results);
        } elseif ($this->results && !current($this->results) && !$this instanceof PaginationInterface) {
            return false;
        } elseif (!current($this->results) && ($this->topCounter < $this->topLimit)) {
            $this->top = min($this->top, $this->topLimit - $this->topCounter);
            $this->skip += count($this->results);
            $this->results = $this->generate();
            $this->topCounter += count($this->results);
        }

        return !!current($this->results);
    }

    public function setMaxPageSize(int $maxPageSize): self
    {
        $this->maxPageSize = $maxPageSize;

        return $this;
    }

    public function getEntity(Primitive $key): ?Entity
    {
        $this->setKey($key);
        return $this->current();
    }

    /**
     * Get a single primitive from the entity set
     *
     * @param  Primitive  $key
     * @param  Property  $property
     *
     * @return Primitive
     */
    public function getPrimitive(Primitive $key, Property $property): ?Primitive
    {
        $entity = $this->getEntity($key);

        if (null === $entity) {
            throw NotFoundException::factory()
                ->target($key->toJson());
        }

        return $entity->getPrimitive($property);
    }

    public function addNavigationBinding(Binding $binding): self
    {
        $this->navigationBindings[] = $binding;

        return $this;
    }

    public function getNavigationBindings(): ObjectArray
    {
        return $this->navigationBindings;
    }

    public function getBindingByNavigationProperty(Navigation $property): ?Binding
    {
        /** @var Binding $navigationBinding */
        foreach ($this->navigationBindings as $navigationBinding) {
            if ($navigationBinding->getPath() === $property) {
                return $navigationBinding;
            }
        }

        return null;
    }

    public function emit(Transaction $transaction): void
    {
        $transaction->outputJsonArrayStart();

        while ($this->valid()) {
            $entity = $this->current();
            $entity->emit($transaction);

            $this->next();

            if (!$this->valid()) {
                break;
            }

            $transaction->outputJsonSeparator();
        }

        $transaction->outputJsonArrayEnd();
    }

    public function response(Transaction $transaction): StreamedResponse
    {
        $transaction->setContentTypeJson();

        foreach (
            [
                $transaction->getCount(), $transaction->getFilter(), $transaction->getOrderBy(),
                $transaction->getSearch(), $transaction->getSkip(), $transaction->getTop(),
                $transaction->getExpand()
            ] as $sqo
        ) {
            /** @var Option $sqo */
            if ($sqo->hasValue() && !is_a($this, $sqo::query_interface)) {
                throw new NotImplementedException(
                    'system_query_option_not_implemented',
                    sprintf('The %s system query option is not supported by this entity set', $sqo::param)
                );
            }
        }

        // Validate $expand
        $expand = $transaction->getExpand();
        $expand->getExpansionRequests($this->getType());

        // Validate $select
        $select = $transaction->getSelect();
        $select->getSelectedProperties($this);

        // Validate $orderby
        $orderby = $transaction->getOrderBy();
        $orderby->getSortOrders($this);

        $skip = $transaction->getSkip();

        $maxPageSize = $transaction->getPreference('maxpagesize');
        $top = $transaction->getTop();
        if (!$top->hasValue() && $maxPageSize) {
            $transaction->preferenceApplied('maxpagesize', $maxPageSize);
            $top->setValue($maxPageSize);
        }

        $this->top = $top->hasValue() && ($top->getValue() < $this->maxPageSize) ? $top->getValue() : $this->maxPageSize;

        if ($skip->hasValue()) {
            $this->skip = $skip->getValue();
        }

        if ($top->hasValue()) {
            $this->topLimit = $top->getValue();
        }

        $setCount = $this->count();

        $metadata = [];

        $select = $transaction->getSelect();

        if ($select->hasValue() && !$select->isStar()) {
            $metadata['context'] = $transaction->getCollectionOfProjectedEntitiesContextUrl(
                $this,
                $select->getValue()
            );
        } else {
            $metadata['context'] = $transaction->getCollectionOfEntitiesContextUrl($this);
        }

        $count = $transaction->getCount();
        if (true === $count->getValue()) {
            $metadata['count'] = $setCount;
        }

        $skip = $transaction->getSkip();

        if ($top->hasValue()) {
            if ($top->getValue() + ($skip->getValue() ?: 0) < $setCount) {
                $np = $transaction->getQueryParams();
                $np['$skip'] = $top->getValue() + ($skip->getValue() ?: 0);
                $metadata['nextLink'] = $transaction->getEntityCollectionResourceUrl($this).'?'.http_build_query(
                        $np,
                        null,
                        '&',
                        PHP_QUERY_RFC3986
                    );
            }
        }

        $metadata = $transaction->getMetadata()->filter($metadata);

        return $transaction->getResponse()->setCallback(function () use ($transaction, $metadata) {
            $transaction->outputJsonObjectStart();

            if ($metadata) {
                $transaction->outputJsonKV($metadata);
                $transaction->outputJsonSeparator();
            }

            $transaction->outputJsonKey('value');
            $this->emit($transaction);
            $transaction->outputJsonObjectEnd();
        });
    }

    public static function pipe(
        Transaction $transaction,
        string $pathComponent,
        ?PipeInterface $argument
    ): ?PipeInterface {
        /** @var ODataModel $data_model */
        $data_model = app()->make(ODataModel::class);
        $lexer = new Lexer($pathComponent);
        try {
            $entitySet = $data_model->getResources()->get($lexer->odataIdentifier());
        } catch (LexerException $e) {
            throw new PathNotHandledException();
        }

        if (!$entitySet instanceof EntitySet) {
            throw new PathNotHandledException();
        }

        if (null !== $argument) {
            throw new BadRequestException(
                'no_entity_set_receiver',
                'Entity set does not support composition from this type'
            );
        }

        $entitySet = $entitySet->asInstance($transaction);

        if ($lexer->finished()) {
            return $entitySet;
        }

        $id = $lexer->matchingParenthesis();
        $lexer = new Lexer($id);

        // Get the default key property
        $keyProperty = $entitySet->getType()->getKey();

        // Test for alternative key syntax
        $alternateKey = $lexer->maybeODataIdentifier();
        if ($alternateKey) {
            if ($lexer->maybeChar('=')) {
                // Test for referenced value syntax
                if ($lexer->maybeChar('@')) {
                    $referencedKey = $lexer->odataIdentifier();
                    $referencedValue = $transaction->getReferencedValue($referencedKey);
                    $lexer = new Lexer($referencedValue);
                }

                $keyProperty = $entitySet->getType()->getProperty($alternateKey);

                if ($keyProperty instanceof Property && !$keyProperty->isAlternativeKey()) {
                    throw new BadRequestException(
                        'property_not_alternative_key',
                        sprintf(
                            'The requested property (%s) is not configured as an alternative key',
                            $alternateKey
                        )
                    );
                }
            } else {
                // Captured value was not an alternative key, reset the lexer
                $lexer = new Lexer($id);
            }
        }

        if (null === $keyProperty) {
            throw new BadRequestException('no_key_property_exists', 'No key property exists for this entity set');
        }

        try {
            $value = $lexer->type($keyProperty->getType());
        } catch (LexerException $e) {
            throw BadRequestException::factory(
                'invalid_identifier_value',
                'The type of the provided identifier value was not valid for this entity type'
            )->lexer($lexer);
        }

        $value->setProperty($keyProperty);

        return $entitySet->getEntity($value);
    }

    public function makeEntity(): Entity
    {
        $entity = new Entity();
        $entity->setEntitySet($this);
        return $entity;
    }

    /**
     * Generate a single page of results, using $this->top and $this->skip, loading the results as Entity objects into $this->result_set
     */
    abstract protected function generate(): array;
}