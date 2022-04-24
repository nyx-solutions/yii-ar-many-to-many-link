<?php

    namespace nyx\db;

    use yii\base\Behavior;
    use yii\base\Event;
    use yii\base\UnknownPropertyException;
    use yii\db\ActiveRecordInterface;
    use yii\db\BaseActiveRecord;
    use yii\db\Exception;
    use yii\db\StaleObjectException;

    /**
     * LinkManyBehavior provides support for ActiveRecord many-to-many relation saving.
     *
     * Configuration example:
     *
     * ```php
     * class Item extends ActiveRecord
     * {
     *     public function behaviors()
     *     {
     *         return [
     *             'linkManyBehavior' => [
     *                 'class' => LinkManyBehavior::className(),
     *                 'relation' => 'groups',
     *                 'relationReferenceAttribute' => 'groupIds',
     *             ],
     *         ];
     *     }
     *
     *     public function getGroups()
     *     {
     *         return $this->hasMany(Group::className(), ['id' => 'groupId'])->viaTable('ItemGroup', ['itemId' => 'id']);
     *     }
     * }
     * ```
     *
     * @property BaseActiveRecord $owner
     * @property array|null       $relationReferenceAttributeValue
     * @property bool             $isRelationReferenceAttributeValueInitialized
     *
     * @author Paul Klimov <klimov.paul@gmail.com>
     * @author Jonatas Sas <atendimento@jsas.com.br>
     * @since  1.0
     */
    class LinkManyToManyBehavior extends Behavior
    {
        /**
         * @var string|null name of the owner model "many to many" relation,
         * which should be handled.
         */
        public ?string $relation = null;

        /**
         * @var string|null name of the owner model attribute, which should be used to set
         * "many to many" relation values.
         * This will establish an owner virtual property, which can be used to specify related record primary keys.
         */
        public ?string $relationReferenceAttribute = null;

        /**
         * @var array additional column values to be saved into the junction table.
         * Each column value can be a callable, which will be invoked during linking to compose actual value.
         * Starting from version 1.0.2, this callable may accept linked model instance as a first parameter.
         * For example:
         *
         * ```php
         * [
         *     'type' => 'user-defined',
         *     'createdAt' => function() {return time();},
         *     'categoryId' => function ($model) {return $model->categoryId;},
         * ]
         * ```
         */
        public array $extraColumns = [];

        /**
         * @var bool whether to delete the pivot model or table row on unlink.
         */
        public bool $deleteOnUnlink = true;

        /**
         * @var array|null relation reference attribute value
         */
        private ?array $_relationReferenceAttributeValue = null;


        /**
         * @param mixed $value relation reference attribute value
         *
         * @return void
         */
        public function setRelationReferenceAttributeValue(mixed $value): void
        {
            $this->_relationReferenceAttributeValue = $value;
        }

        /**
         * @return array relation reference attribute value
         */
        public function getRelationReferenceAttributeValue(): array
        {
            if ($this->_relationReferenceAttributeValue === null) {
                $this->_relationReferenceAttributeValue = $this->initRelationReferenceAttributeValue();
            }

            return $this->_relationReferenceAttributeValue;
        }

        /**
         * @return bool whether the relation reference attribute value has been initialized or not.
         */
        public function getIsRelationReferenceAttributeValueInitialized(): bool
        {
            return ($this->_relationReferenceAttributeValue !== null);
        }

        /**
         * Initializes value of [[relationAttributeValue]] in case it is not set.
         *
         * @return array relation attribute value.
         */
        protected function initRelationReferenceAttributeValue(): array
        {
            $result = [];

            $relatedRecords = $this->owner->{$this->relation};

            if (!empty($relatedRecords)) {
                foreach ($relatedRecords as $relatedRecord) {
                    /** @var ActiveRecordInterface $relatedRecord */
                    $result[] = $this->normalizePrimaryKey($relatedRecord->getPrimaryKey());
                }
            }

            return $result;
        }

        /**
         * @param mixed $primaryKey raw primary key value.
         *
         * @return string|int normalized value.
         */
        protected function normalizePrimaryKey(mixed $primaryKey): string|int
        {
            if (is_object($primaryKey) && method_exists($primaryKey, '__toString')) {
                // handle complex types like [[\MongoId]] :
                $primaryKey = $primaryKey->__toString();
            }

            return $primaryKey;
        }

        // Property Access Extension:

        /**
         * PHP getter magic method.
         * This method is overridden so that relation attribute can be accessed like property.
         *
         * @param string $name property name
         *
         * @return mixed property value
         * @throws UnknownPropertyException if the property is not defined
         */
        public function __get($name)
        {
            try {
                return parent::__get($name);
            } catch (UnknownPropertyException $exception) {
                if ($name === $this->relationReferenceAttribute) {
                    return $this->getRelationReferenceAttributeValue();
                }
                throw $exception;
            }
        }

        /**
         * PHP setter magic method.
         * This method is overridden so that relation attribute can be accessed like property.
         *
         * @param string $name  property name
         * @param mixed  $value property value
         *
         * @throws UnknownPropertyException if the property is not defined
         */
        public function __set($name, $value)
        {
            try {
                parent::__set($name, $value);
            } catch (UnknownPropertyException $exception) {
                if ($name === $this->relationReferenceAttribute) {
                    $this->setRelationReferenceAttributeValue($value);
                } else {
                    throw $exception;
                }
            }
        }

        /**
         * @inheritdoc
         */
        public function canGetProperty($name, $checkVars = true)
        {
            if (parent::canGetProperty($name, $checkVars)) {
                return true;
            }
            return ($name === $this->relationReferenceAttribute);
        }

        /**
         * @inheritdoc
         */
        public function canSetProperty($name, $checkVars = true)
        {
            if (parent::canSetProperty($name, $checkVars)) {
                return true;
            }
            return ($name === $this->relationReferenceAttribute);
        }

        // Events :

        /**
         * @inheritdoc
         */
        public function events()
        {
            return [
                BaseActiveRecord::EVENT_AFTER_INSERT => 'afterSave',
                BaseActiveRecord::EVENT_AFTER_UPDATE => 'afterSave',
                BaseActiveRecord::EVENT_AFTER_DELETE => 'afterDelete',
            ];
        }

        /**
         * Handles owner 'afterInsert' and 'afterUpdate' events, ensuring related models are linked.
         *
         * @param Event $event event instance.
         *
         * @throws Exception
         * @throws StaleObjectException
         *
         * @noinspection PhpUnusedParameterInspection
         */
        public function afterSave($event)
        {
            if (!$this->getIsRelationReferenceAttributeValueInitialized()) {
                return;
            }

            $linkModels   = [];
            $unlinkModels = [];

            $newReferences = $this->getRelationReferenceAttributeValue();

            if (is_array($newReferences)) {
                $newReferences = array_unique($newReferences);
            } elseif (empty($newReferences)) {
                $newReferences = [];
            } else {
                $newReferences = [$newReferences];
            }

            foreach ($this->owner->{$this->relation} as $relatedModel) {
                /** @var ActiveRecordInterface $relatedModel */
                $primaryKey = $this->normalizePrimaryKey($relatedModel->getPrimaryKey());

                if (($primaryKeyPosition = array_search($primaryKey, $newReferences, true)) !== false) {
                    unset($newReferences[$primaryKeyPosition]);
                } else {
                    $unlinkModels[] = $relatedModel;
                }
            }

            if (!empty($newReferences)) {
                /* @var ActiveRecordInterface $relatedClass */
                $relatedClass = $this->owner->getRelation($this->relation)->modelClass;

                $linkModels = $relatedClass::findAll(array_values($newReferences));
            }

            foreach ($unlinkModels as $model) {
                $this->owner->unlink($this->relation, $model, $this->deleteOnUnlink);
            }

            foreach ($linkModels as $model) {
                $this->owner->link($this->relation, $model, $this->composeLinkExtraColumns($model));
            }
        }

        /**
         * Handles owner 'afterDelete' event, ensuring related models are unlinked.
         *
         * @param Event $event event instance.
         *
         * @noinspection PhpUnusedParameterInspection
         */
        public function afterDelete($event)
        {
            $this->owner->unlinkAll($this->relation, $this->deleteOnUnlink);
        }

        /**
         * Composes actual link extra columns value from [[extraColumns]], resolving possible callbacks.
         *
         * @param ActiveRecordInterface|null $model linked model instance.
         *
         * @return array additional column values to be saved into the junction table.
         */
        protected function composeLinkExtraColumns($model = null)
        {
            if (empty($this->extraColumns)) {
                return [];
            }

            $extraColumns = [];

            foreach ($this->extraColumns as $column => $value) {
                if (!is_scalar($value) && is_callable($value)) {
                    $value = $value($model);
                }

                $extraColumns[$column] = $value;
            }

            return $extraColumns;
        }
    }
