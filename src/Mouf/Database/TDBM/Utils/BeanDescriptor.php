<?php

namespace Mouf\Database\TDBM\Utils;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Mouf\Database\SchemaAnalyzer\SchemaAnalyzer;
use Mouf\Database\TDBM\TDBMException;
use Mouf\Database\TDBM\TDBMSchemaAnalyzer;

/**
 * This class represents a bean.
 */
class BeanDescriptor
{
    /**
     * @var Table
     */
    private $table;

    /**
     * @var SchemaAnalyzer
     */
    private $schemaAnalyzer;

    /**
     * @var Schema
     */
    private $schema;

    /**
     * @var AbstractBeanPropertyDescriptor[]
     */
    private $beanPropertyDescriptors = [];

    /**
     * @var TDBMSchemaAnalyzer
     */
    private $tdbmSchemaAnalyzer;

    public function __construct(Table $table, SchemaAnalyzer $schemaAnalyzer, Schema $schema, TDBMSchemaAnalyzer $tdbmSchemaAnalyzer)
    {
        $this->table = $table;
        $this->schemaAnalyzer = $schemaAnalyzer;
        $this->schema = $schema;
        $this->tdbmSchemaAnalyzer = $tdbmSchemaAnalyzer;
        $this->initBeanPropertyDescriptors();
    }

    private function initBeanPropertyDescriptors()
    {
        $this->beanPropertyDescriptors = $this->getProperties($this->table);
    }

    /**
     * Returns the foreign-key the column is part of, if any. null otherwise.
     *
     * @param Table  $table
     * @param Column $column
     *
     * @return ForeignKeyConstraint|null
     */
    private function isPartOfForeignKey(Table $table, Column $column)
    {
        $localColumnName = $column->getName();
        foreach ($table->getForeignKeys() as $foreignKey) {
            foreach ($foreignKey->getColumns() as $columnName) {
                if ($columnName === $localColumnName) {
                    return $foreignKey;
                }
            }
        }

        return;
    }

    /**
     * @return AbstractBeanPropertyDescriptor[]
     */
    public function getBeanPropertyDescriptors()
    {
        return $this->beanPropertyDescriptors;
    }

    /**
     * Returns the list of columns that are not nullable and not autogenerated for a given table and its parent.
     *
     * @return AbstractBeanPropertyDescriptor[]
     */
    public function getConstructorProperties()
    {
        $constructorProperties = array_filter($this->beanPropertyDescriptors, function (AbstractBeanPropertyDescriptor $property) {
            return $property->isCompulsory();
        });

        return $constructorProperties;
    }

    /**
     * Returns the list of columns that have default values for a given table.
     *
     * @return AbstractBeanPropertyDescriptor[]
     */
    public function getPropertiesWithDefault()
    {
        $properties = $this->getPropertiesForTable($this->table);
        $defaultProperties = array_filter($properties, function (AbstractBeanPropertyDescriptor $property) {
            return $property->hasDefault();
        });

        return $defaultProperties;
    }

    /**
     * Returns the list of properties exposed as getters and setters in this class.
     *
     * @return AbstractBeanPropertyDescriptor[]
     */
    public function getExposedProperties()
    {
        $exposedProperties = array_filter($this->beanPropertyDescriptors, function (AbstractBeanPropertyDescriptor $property) {
            return $property->getTable()->getName() == $this->table->getName();
        });

        return $exposedProperties;
    }

    /**
     * Returns the list of properties for this table (including parent tables).
     *
     * @param Table $table
     *
     * @return AbstractBeanPropertyDescriptor[]
     */
    private function getProperties(Table $table)
    {
        $parentRelationship = $this->schemaAnalyzer->getParentRelationship($table->getName());
        if ($parentRelationship) {
            $parentTable = $this->schema->getTable($parentRelationship->getForeignTableName());
            $properties = $this->getProperties($parentTable);
            // we merge properties by overriding property names.
            $localProperties = $this->getPropertiesForTable($table);
            foreach ($localProperties as $name => $property) {
                // We do not override properties if this is a primary key!
                if ($property->isPrimaryKey()) {
                    continue;
                }
                $properties[$name] = $property;
            }
        } else {
            $properties = $this->getPropertiesForTable($table);
        }

        return $properties;
    }

    /**
     * Returns the list of properties for this table (ignoring parent tables).
     *
     * @param Table $table
     *
     * @return AbstractBeanPropertyDescriptor[]
     */
    private function getPropertiesForTable(Table $table)
    {
        $parentRelationship = $this->schemaAnalyzer->getParentRelationship($table->getName());
        if ($parentRelationship) {
            $ignoreColumns = $parentRelationship->getLocalColumns();
        } else {
            $ignoreColumns = [];
        }

        $beanPropertyDescriptors = [];

        foreach ($table->getColumns() as $column) {
            if (array_search($column->getName(), $ignoreColumns) !== false) {
                continue;
            }

            $fk = $this->isPartOfForeignKey($table, $column);
            if ($fk !== null) {
                // Check that previously added descriptors are not added on same FK (can happen with multi key FK).
                foreach ($beanPropertyDescriptors as $beanDescriptor) {
                    if ($beanDescriptor instanceof ObjectBeanPropertyDescriptor && $beanDescriptor->getForeignKey() === $fk) {
                        continue 2;
                    }
                }
                // Check that this property is not an inheritance relationship
                $parentRelationship = $this->schemaAnalyzer->getParentRelationship($table->getName());
                if ($parentRelationship === $fk) {
                    continue;
                }

                $beanPropertyDescriptors[] = new ObjectBeanPropertyDescriptor($table, $fk, $this->schemaAnalyzer);
            } else {
                $beanPropertyDescriptors[] = new ScalarBeanPropertyDescriptor($table, $column);
            }
        }

        // Now, let's get the name of all properties and let's check there is no duplicate.
        /** @var $names AbstractBeanPropertyDescriptor[] */
        $names = [];
        foreach ($beanPropertyDescriptors as $beanDescriptor) {
            $name = $beanDescriptor->getUpperCamelCaseName();
            if (isset($names[$name])) {
                $names[$name]->useAlternativeName();
                $beanDescriptor->useAlternativeName();
            } else {
                $names[$name] = $beanDescriptor;
            }
        }

        // Final check (throw exceptions if problem arises)
        $names = [];
        foreach ($beanPropertyDescriptors as $beanDescriptor) {
            $name = $beanDescriptor->getUpperCamelCaseName();
            if (isset($names[$name])) {
                throw new TDBMException('Unsolvable name conflict while generating method name');
            } else {
                $names[$name] = $beanDescriptor;
            }
        }

        // Last step, let's rebuild the list with a map:
        $beanPropertyDescriptorsMap = [];
        foreach ($beanPropertyDescriptors as $beanDescriptor) {
            $beanPropertyDescriptorsMap[$beanDescriptor->getLowerCamelCaseName()] = $beanDescriptor;
        }

        return $beanPropertyDescriptorsMap;
    }

    public function generateBeanConstructor()
    {
        $constructorProperties = $this->getConstructorProperties();

        $constructorCode = '    /**
     * The constructor takes all compulsory arguments.
     *
%s
     */
    public function __construct(%s) {
%s%s
%s    }
    ';

        $paramAnnotations = [];
        $arguments = [];
        $assigns = [];
        $parentConstructorArguments = [];

        foreach ($constructorProperties as $property) {
            $className = $property->getClassName();
            if ($className) {
                $arguments[] = $className.' '.$property->getVariableName();
            } else {
                $arguments[] = $property->getVariableName();
            }
            $paramAnnotations[] = $property->getParamAnnotation();
            if ($property->getTable()->getName() === $this->table->getName()) {
                $assigns[] = $property->getConstructorAssignCode();
            } else {
                $parentConstructorArguments[] = $property->getVariableName();
            }
        }

        $parentConstructorCode = sprintf("        parent::__construct(%s);\n", implode(', ', $parentConstructorArguments));

        $defaultAssigns = [];
        foreach ($this->getPropertiesWithDefault() as $property) {
            $defaultAssigns[] = $property->assignToDefaultCode();
        }

        return sprintf($constructorCode, implode("\n", $paramAnnotations), implode(', ', $arguments), $parentConstructorCode, implode("\n", $assigns), implode("\n", $defaultAssigns));
    }

    public function generateDirectForeignKeysCode()
    {
        $fks = $this->tdbmSchemaAnalyzer->getIncomingForeignKeys($this->table->getName());

        $fksByTable = [];

        foreach ($fks as $fk) {
            $fksByTable[$fk->getLocalTableName()][] = $fk;
        }

        /* @var $fksByMethodName ForeignKeyConstraint[] */
        $fksByMethodName = [];

        foreach ($fksByTable as $tableName => $fksForTable) {
            if (count($fksForTable) > 1) {
                foreach ($fksForTable as $fk) {
                    $methodName = 'get'.TDBMDaoGenerator::toCamelCase($fk->getLocalTableName()).'By';

                    $camelizedColumns = array_map(['Mouf\\Database\\TDBM\\Utils\\TDBMDaoGenerator', 'toCamelCase'], $fk->getLocalColumns());

                    $methodName .= implode('And', $camelizedColumns);

                    $fksByMethodName[$methodName] = $fk;
                }
            } else {
                $methodName = 'get'.TDBMDaoGenerator::toCamelCase($fksForTable[0]->getLocalTableName());
                $fksByMethodName[$methodName] = $fksForTable[0];
            }
        }

        $code = '';

        foreach ($fksByMethodName as $methodName => $fk) {
            $getterCode = '    /**
     * Returns the list of %s pointing to this bean via the %s column.
     *
     * @return %s[]|AlterableResultIterator
     */
    public function %s()
    {
        return $this->retrieveManyToOneRelationshipsStorage(%s, %s, %s, %s);
    }

';

            $beanClass = TDBMDaoGenerator::getBeanNameFromTableName($fk->getLocalTableName());
            $code .= sprintf($getterCode,
                $beanClass,
                implode(', ', $fk->getColumns()),
                $beanClass,
                $methodName,
                var_export($fk->getLocalTableName(), true),
                var_export($fk->getName(), true),
                var_export($fk->getLocalTableName(), true),
                $this->getFilters($fk)
            );
        }

        return $code;
    }

    private function getFilters(ForeignKeyConstraint $fk) : string
    {
        $counter = 0;
        $parameters = [];

        $pkColumns = $this->table->getPrimaryKeyColumns();

        foreach ($fk->getLocalColumns() as $columnName) {
            $pkColumn = $pkColumns[$counter];
            $parameters[] = sprintf('%s => $this->get(%s, %s)', var_export($fk->getLocalTableName().'.'.$columnName, true), var_export($pkColumn, true), var_export($this->table->getName(), true));
            ++$counter;
        }
        $parametersCode = '[ '.implode(', ', $parameters).' ]';

        return $parametersCode;
    }

    /**
     * Generate code section about pivot tables.
     *
     * @return string
     */
    public function generatePivotTableCode()
    {
        $finalDescs = $this->getPivotTableDescriptors();

        $code = '';

        foreach ($finalDescs as $desc) {
            $code .= $this->getPivotTableCode($desc['name'], $desc['table'], $desc['localFK'], $desc['remoteFK']);
        }

        return $code;
    }

    private function getPivotTableDescriptors()
    {
        $descs = [];
        foreach ($this->schemaAnalyzer->detectJunctionTables(true) as $table) {
            // There are exactly 2 FKs since this is a pivot table.
            $fks = array_values($table->getForeignKeys());

            if ($fks[0]->getForeignTableName() === $this->table->getName()) {
                $localFK = $fks[0];
                $remoteFK = $fks[1];
            } elseif ($fks[1]->getForeignTableName() === $this->table->getName()) {
                $localFK = $fks[1];
                $remoteFK = $fks[0];
            } else {
                continue;
            }

            $descs[$remoteFK->getForeignTableName()][] = [
                'table' => $table,
                'localFK' => $localFK,
                'remoteFK' => $remoteFK,
            ];
        }

        $finalDescs = [];
        foreach ($descs as $descArray) {
            if (count($descArray) > 1) {
                foreach ($descArray as $desc) {
                    $desc['name'] = TDBMDaoGenerator::toCamelCase($desc['remoteFK']->getForeignTableName()).'By'.TDBMDaoGenerator::toCamelCase($desc['table']->getName());
                    $finalDescs[] = $desc;
                }
            } else {
                $desc = $descArray[0];
                $desc['name'] = TDBMDaoGenerator::toCamelCase($desc['remoteFK']->getForeignTableName());
                $finalDescs[] = $desc;
            }
        }

        return $finalDescs;
    }

    public function getPivotTableCode($name, Table $table, ForeignKeyConstraint $localFK, ForeignKeyConstraint $remoteFK)
    {
        $singularName = TDBMDaoGenerator::toSingular($name);
        $pluralName = $name;
        $remoteBeanName = TDBMDaoGenerator::getBeanNameFromTableName($remoteFK->getForeignTableName());
        $variableName = '$'.TDBMDaoGenerator::toVariableName($remoteBeanName);
        $pluralVariableName = $variableName.'s';

        $str = '    /**
     * Returns the list of %s associated to this bean via the %s pivot table.
     *
     * @return %s[]
     */
    public function get%s() {
        return $this->_getRelationships(%s);
    }
';

        $getterCode = sprintf($str, $remoteBeanName, $table->getName(), $remoteBeanName, $name, var_export($remoteFK->getLocalTableName(), true));

        $str = '    /**
     * Adds a relationship with %s associated to this bean via the %s pivot table.
     *
     * @param %s %s
     */
    public function add%s(%s %s) {
        return $this->addRelationship(%s, %s);
    }
';

        $adderCode = sprintf($str, $remoteBeanName, $table->getName(), $remoteBeanName, $variableName, $singularName, $remoteBeanName, $variableName, var_export($remoteFK->getLocalTableName(), true), $variableName);

        $str = '    /**
     * Deletes the relationship with %s associated to this bean via the %s pivot table.
     *
     * @param %s %s
     */
    public function remove%s(%s %s) {
        return $this->_removeRelationship(%s, %s);
    }
';

        $removerCode = sprintf($str, $remoteBeanName, $table->getName(), $remoteBeanName, $variableName, $singularName, $remoteBeanName, $variableName, var_export($remoteFK->getLocalTableName(), true), $variableName);

        $str = '    /**
     * Returns whether this bean is associated with %s via the %s pivot table.
     *
     * @param %s %s
     * @return bool
     */
    public function has%s(%s %s) {
        return $this->hasRelationship(%s, %s);
    }
';

        $hasCode = sprintf($str, $remoteBeanName, $table->getName(), $remoteBeanName, $variableName, $singularName, $remoteBeanName, $variableName, var_export($remoteFK->getLocalTableName(), true), $variableName);

        $str = '    /**
     * Sets all relationships with %s associated to this bean via the %s pivot table.
     * Exiting relationships will be removed and replaced by the provided relationships.
     *
     * @param %s[] %s
     */
    public function set%s(array %s) {
        return $this->setRelationships(%s, %s);
    }
';

        $setterCode = sprintf($str, $remoteBeanName, $table->getName(), $remoteBeanName, $pluralVariableName, $pluralName, $pluralVariableName, var_export($remoteFK->getLocalTableName(), true), $pluralVariableName);

        $code = $getterCode.$adderCode.$removerCode.$hasCode.$setterCode;

        return $code;
    }

    public function generateJsonSerialize()
    {
        $tableName = $this->table->getName();
        $parentFk = $this->schemaAnalyzer->getParentRelationship($tableName);
        if ($parentFk !== null) {
            $initializer = '$array = parent::jsonSerialize($stopRecursion);';
        } else {
            $initializer = '$array = [];';
        }

        $str = '
    /**
     * Serializes the object for JSON encoding
     *
     * @param bool $stopRecursion Parameter used internally by TDBM to stop embedded objects from embedding other objects.
     * @return array
     */
    public function jsonSerialize($stopRecursion = false)
    {
        %s
%s
%s
        return $array;
    }
';

        $propertiesCode = '';
        foreach ($this->beanPropertyDescriptors as $beanPropertyDescriptor) {
            $propertiesCode .= $beanPropertyDescriptor->getJsonSerializeCode();
        }

        // Many to many relationships:

        $descs = $this->getPivotTableDescriptors();

        $many2manyCode = '';

        foreach ($descs as $desc) {
            $remoteFK = $desc['remoteFK'];
            $remoteBeanName = TDBMDaoGenerator::getBeanNameFromTableName($remoteFK->getForeignTableName());
            $variableName = '$'.TDBMDaoGenerator::toVariableName($remoteBeanName);

            $many2manyCode .= '        if (!$stopRecursion) {
            $array[\''.lcfirst($desc['name']).'\'] = array_map(function('.$remoteBeanName.' '.$variableName.') {
                return '.$variableName.'->jsonSerialize(true);
            }, $this->get'.$desc['name'].'());
        }
        ';
        }

        return sprintf($str, $initializer, $propertiesCode, $many2manyCode);
    }

    /**
     * Returns as an array the class we need to extend from and the list of use statements.
     *
     * @return array
     */
    private function generateExtendsAndUseStatements(ForeignKeyConstraint $parentFk = null)
    {
        $classes = [];
        if ($parentFk !== null) {
            $extends = TDBMDaoGenerator::getBeanNameFromTableName($parentFk->getForeignTableName());
            $classes[] = $extends;
        }

        foreach ($this->getBeanPropertyDescriptors() as $beanPropertyDescriptor) {
            $className = $beanPropertyDescriptor->getClassName();
            if (null !== $className) {
                $classes[] = $beanPropertyDescriptor->getClassName();
            }
        }

        foreach ($this->getPivotTableDescriptors() as $descriptor) {
            /* @var $fk ForeignKeyConstraint */
            $fk = $descriptor['remoteFK'];
            $classes[] = TDBMDaoGenerator::getBeanNameFromTableName($fk->getForeignTableName());
        }

        $classes = array_flip(array_flip($classes));

        return $classes;
    }

    /**
     * Writes the PHP bean file with all getters and setters from the table passed in parameter.
     *
     * @param string $beannamespace The namespace of the bean
     */
    public function generatePhpCode($beannamespace)
    {
        $tableName = $this->table->getName();
        $baseClassName = TDBMDaoGenerator::getBaseBeanNameFromTableName($tableName);
        $className = TDBMDaoGenerator::getBeanNameFromTableName($tableName);
        $parentFk = $this->schemaAnalyzer->getParentRelationship($tableName);

        $classes = $this->generateExtendsAndUseStatements($parentFk);

        $uses = array_map(function ($className) use ($beannamespace) {
            return 'use '.$beannamespace.'\\'.$className.";\n";
        }, $classes);
        $use = implode('', $uses);

        if ($parentFk !== null) {
            $extends = TDBMDaoGenerator::getBeanNameFromTableName($parentFk->getForeignTableName());
        } else {
            $extends = 'AbstractTDBMObject';
            $use .= "use Mouf\\Database\\TDBM\\AbstractTDBMObject;\n";
        }

        $str = "<?php
namespace {$beannamespace}\\Generated;

use Mouf\\Database\\TDBM\\ResultIterator;
$use
/*
 * This file has been automatically generated by TDBM.
 * DO NOT edit this file, as it might be overwritten.
 * If you need to perform changes, edit the $className class instead!
 */

/**
 * The $baseClassName class maps the '$tableName' table in database.
 */
class $baseClassName extends $extends implements \\JsonSerializable
{
";

        $str .= $this->generateBeanConstructor();

        foreach ($this->getExposedProperties() as $property) {
            $str .= $property->getGetterSetterCode();
        }

        $str .= $this->generateDirectForeignKeysCode();
        $str .= $this->generatePivotTableCode();
        $str .= $this->generateJsonSerialize();

        $str .= $this->generateGetUsedTablesCode();

        $str .= '}
';

        return $str;
    }

    /**
     * @param string $beanNamespace
     * @param string $beanClassName
     *
     * @return array first element: list of used beans, second item: PHP code as a string
     */
    public function generateFindByDaoCode($beanNamespace, $beanClassName)
    {
        $code = '';
        $usedBeans = [];
        foreach ($this->table->getIndexes() as $index) {
            if (!$index->isPrimary()) {
                list($usedBeansForIndex, $codeForIndex) = $this->generateFindByDaoCodeForIndex($index, $beanNamespace, $beanClassName);
                $code .= $codeForIndex;
                $usedBeans = array_merge($usedBeans, $usedBeansForIndex);
            }
        }

        return [$usedBeans, $code];
    }

    /**
     * @param Index  $index
     * @param string $beanNamespace
     * @param string $beanClassName
     *
     * @return array first element: list of used beans, second item: PHP code as a string
     */
    private function generateFindByDaoCodeForIndex(Index $index, $beanNamespace, $beanClassName)
    {
        $columns = $index->getColumns();
        $usedBeans = [];

        /*
         * The list of elements building this index (expressed as columns or foreign keys)
         * @var AbstractBeanPropertyDescriptor[]
         */
        $elements = [];

        foreach ($columns as $column) {
            $fk = $this->isPartOfForeignKey($this->table, $this->table->getColumn($column));
            if ($fk !== null) {
                if (!in_array($fk, $elements)) {
                    $elements[] = new ObjectBeanPropertyDescriptor($this->table, $fk, $this->schemaAnalyzer);
                }
            } else {
                $elements[] = new ScalarBeanPropertyDescriptor($this->table, $this->table->getColumn($column));
            }
        }

        // If the index is actually only a foreign key, let's bypass it entirely.
        if (count($elements) === 1 && $elements[0] instanceof ObjectBeanPropertyDescriptor) {
            return [[], ''];
        }

        $methodNameComponent = [];
        $functionParameters = [];
        $first = true;
        foreach ($elements as $element) {
            $methodNameComponent[] = $element->getUpperCamelCaseName();
            $functionParameter = $element->getClassName();
            if ($functionParameter) {
                $usedBeans[] = $beanNamespace.'\\'.$functionParameter;
                $functionParameter .= ' ';
            }
            $functionParameter .= $element->getVariableName();
            if ($first) {
                $first = false;
            } else {
                $functionParameter .= ' = null';
            }
            $functionParameters[] = $functionParameter;
        }
        if ($index->isUnique()) {
            $methodName = 'findOneBy'.implode('And', $methodNameComponent);
            $calledMethod = 'findOne';
            $returnType = "{$beanClassName}";
        } else {
            $methodName = 'findBy'.implode('And', $methodNameComponent);
            $returnType = "{$beanClassName}[]|ResultIterator|ResultArray";
            $calledMethod = 'find';
        }
        $functionParametersString = implode(', ', $functionParameters);

        $count = 0;

        $params = [];
        $filterArrayCode = '';
        $commentArguments = [];
        foreach ($elements as $element) {
            $params[] = $element->getParamAnnotation();
            if ($element instanceof ScalarBeanPropertyDescriptor) {
                $filterArrayCode .= '            '.var_export($element->getColumnName(), true).' => '.$element->getVariableName().",\n";
            } else {
                ++$count;
                $filterArrayCode .= '            '.$count.' => '.$element->getVariableName().",\n";
            }
            $commentArguments[] = substr($element->getVariableName(), 1);
        }
        $paramsString = implode("\n", $params);

        $code = "
    /**
     * Get a list of $beanClassName filtered by ".implode(', ', $commentArguments).".
     *
$paramsString
     * @param mixed \$orderBy The order string
     * @param array \$additionalTablesFetch A list of additional tables to fetch (for performance improvement)
     * @param string \$mode Either TDBMService::MODE_ARRAY or TDBMService::MODE_CURSOR (for large datasets). Defaults to TDBMService::MODE_ARRAY.
     * @return $returnType
     */
    public function $methodName($functionParametersString, \$orderBy = null, array \$additionalTablesFetch = array(), \$mode = null)
    {
        \$filter = [
".$filterArrayCode."        ];
        return \$this->$calledMethod(\$filter, [], \$orderBy, \$additionalTablesFetch, \$mode);
    }
";

        return [$usedBeans, $code];
    }

    /**
     * Generates the code for the getUsedTable protected method.
     *
     * @return string
     */
    private function generateGetUsedTablesCode()
    {
        $hasParentRelationship = $this->schemaAnalyzer->getParentRelationship($this->table->getName()) !== null;
        if ($hasParentRelationship) {
            $code = sprintf('        $tables = parent::getUsedTables();
        $tables[] = %s;
        
        return $tables;', var_export($this->table->getName(), true));
        } else {
            $code = sprintf('        return [ %s ];', var_export($this->table->getName(), true));
        }

        return sprintf('
    /**
     * Returns an array of used tables by this bean (from parent to child relationship).
     *
     * @return string[]
     */
    protected function getUsedTables()
    {
%s    
    }
', $code);
    }
}
