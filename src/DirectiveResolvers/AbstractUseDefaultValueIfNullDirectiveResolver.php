<?php

declare(strict_types=1);

namespace PoP\BasicDirectives\DirectiveResolvers;

use PoP\ComponentModel\Schema\SchemaHelpers;
use PoP\ComponentModel\Schema\SchemaDefinition;
use PoP\Translation\Facades\TranslationAPIFacade;
use PoP\ComponentModel\TypeResolvers\TypeResolverInterface;
use PoP\ComponentModel\Facades\Schema\FieldQueryInterpreterFacade;
use PoP\ComponentModel\DirectiveResolvers\AbstractSchemaDirectiveResolver;

abstract class AbstractUseDefaultValueIfNullDirectiveResolver extends AbstractSchemaDirectiveResolver
{
    public const ARGVALUE_CONDITION_IS_NULL = 'is_null';
    public const ARGVALUE_CONDITION_IS_EMPTY = 'is_empty';

    protected function getDefaultValue()
    {
        return null;
    }

    public function resolveDirective(TypeResolverInterface $typeResolver, array &$idsDataFields, array &$succeedingPipelineIDsDataFields, array &$succeedingPipelineDirectiveResolverInstances, array &$resultIDItems, array &$unionDBKeyIDs, array &$dbItems, array &$previousDBItems, array &$variables, array &$messages, array &$dbErrors, array &$dbWarnings, array &$dbDeprecations, array &$schemaErrors, array &$schemaWarnings, array &$schemaDeprecations)
    {
        // Replace all the NULL results with the default value
        $fieldQueryInterpreter = FieldQueryInterpreterFacade::getInstance();
        $fieldOutputKeyCache = [];
        foreach ($idsDataFields as $id => $dataFields) {
            // Use either the default value passed under param "value" or, if this is NULL, use a predefined value
            $expressions = $this->getExpressionsForResultItem($id, $variables, $messages);
            $resultItem = $resultIDItems[$id];
            list(
                $resultItemValidDirective,
                $resultItemDirectiveName,
                $resultItemDirectiveArgs
            ) = $this->dissectAndValidateDirectiveForResultItem($typeResolver, $resultItem, $variables, $expressions, $dbErrors, $dbWarnings, $dbDeprecations);
            // Check that the directive is valid. If it is not, $dbErrors will have the error already added
            if (is_null($resultItemValidDirective)) {
                continue;
            }
            // Take the default value from the directiveArgs
            $defaultValue = $resultItemDirectiveArgs['value'] ?? $this->getDefaultValue();
            $condition = $resultItemDirectiveArgs['condition'] ?? $this->getDefaultCondition();
            if (!is_null($defaultValue)) {
                foreach ($dataFields['direct'] as $field) {
                    // Get the fieldOutputKey from the cache, or calculate it
                    if (is_null($fieldOutputKeyCache[$field])) {
                        $fieldOutputKeyCache[$field] = $fieldQueryInterpreter->getFieldOutputKey($field);
                    }
                    $fieldOutputKey = $fieldOutputKeyCache[$field];
                    // If it is null, replace it with the default value
                    if ($this->matchesCondition($condition, $dbItems[$id][$fieldOutputKey])) {
                        $dbItems[$id][$fieldOutputKey] = $defaultValue;
                    }
                }
            }
        }
    }
    /**
     * Indicate if the value matches the condition under which to inject the default value
     *
     * @param mixed $value
     * @return boolean
     */
    protected function matchesCondition(string $condition, $value): bool
    {
        switch ($condition) {
            case self::ARGVALUE_CONDITION_IS_NULL:
                return is_null($value);
            case self::ARGVALUE_CONDITION_IS_EMPTY:
                return empty($value);
        }
        return false;
    }
    public function getSchemaDirectiveDescription(TypeResolverInterface $typeResolver): ?string
    {
        $translationAPI = TranslationAPIFacade::getInstance();
        $defaultValue = $this->getDefaultValue();
        if (is_null($defaultValue)) {
            return $translationAPI->__('If the value of the field is `NULL`, replace it with the value provided under argument \'value\'', 'basic-directives');
        }
        return $translationAPI->__('If the value of the field is `NULL`, replace it with either the value provided under argument \'value\', or with a default value configured in the directive resolver', 'basic-directives');
    }
    public function getSchemaDirectiveArgs(TypeResolverInterface $typeResolver): array
    {
        $translationAPI = TranslationAPIFacade::getInstance();
        $schemaDirectiveArg = [
            SchemaDefinition::ARGNAME_NAME => 'value',
            SchemaDefinition::ARGNAME_TYPE => SchemaDefinition::TYPE_MIXED,
            SchemaDefinition::ARGNAME_DESCRIPTION => $translationAPI->__('If the value of the field is `NULL`, replace it with the value from this argument', 'basic-directives'),
        ];
        $defaultValue = $this->getDefaultValue();
        if (is_null($defaultValue)) {
            $schemaDirectiveArg[SchemaDefinition::ARGNAME_MANDATORY] = true;
        } else {
            $schemaDirectiveArg[SchemaDefinition::ARGNAME_DEFAULT_VALUE] = $defaultValue;
        }
        return [
            $schemaDirectiveArg,
            [
                SchemaDefinition::ARGNAME_NAME => 'condition',
                SchemaDefinition::ARGNAME_TYPE => SchemaDefinition::TYPE_ENUM,
                SchemaDefinition::ARGNAME_DESCRIPTION => $translationAPI->__('Condition under which using the default value kicks in', 'basic-directives'),
                SchemaDefinition::ARGNAME_ENUMVALUES => SchemaHelpers::convertToSchemaFieldArgEnumValueDefinitions(
                    $this->getSchemaFieldShapeValues()
                ),
                SchemaDefinition::ARGNAME_DEFAULT_VALUE => $this->getDefaultCondition(),
            ]
        ];
    }

    protected function getDefaultCondition(): string
    {
        return self::ARGVALUE_CONDITION_IS_NULL;
    }

    protected function getSchemaFieldShapeValues()
    {
        return [
            self::ARGVALUE_CONDITION_IS_NULL,
            self::ARGVALUE_CONDITION_IS_EMPTY,
        ];
    }
}
