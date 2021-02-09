<?php

namespace Fromholdio\ElementalMultiArea\Extensions;

use DNADesign\Elemental\Extensions\ElementalAreasExtension;
use DNADesign\Elemental\Forms\ElementalAreaField;
use DNADesign\Elemental\Models\BaseElement;
use DNADesign\ElementalUserForms\Model\ElementForm;
use SilverStripe\CMS\Model\RedirectorPage;
use SilverStripe\CMS\Model\VirtualPage;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;

class MultiElementalAreasExtension extends ElementalAreasExtension
{
    private static $elemental_tab_path = 'Root.Main';
    private static $elemental_insert_before = 'Metadata';
    private static $elemental_replace_contentfield = false;
    private static $elemental_clear_contentfield = false;
    private static $elemental_base_class = BaseElement::class;

    private static $clear_contentfield = false;
    private static $sort_types_alphabetically = true;
    private static $ignored_classes = [
        RedirectorPage::class,
        VirtualPage::class
    ];

    private static $elemental_relations;

    public function updateCMSFields(FieldList $fields)
    {
        if (!$this->getOwner()->supportsElemental()) {
            return;
        }

        $doReplaceContentField = $this->getOwner()->getDoElementalReplaceContentField();

        $elementalAreaRelations = $this->getOwner()->getElementalRelations();
        foreach ($elementalAreaRelations as $eaRelationName) {

            $fieldName = $eaRelationName . 'ID';

            $fields->removeByName($fieldName);

            if (!$this->getOwner()->isInDB()) {
                continue;
            }

            $tabPath = $this->getOwner()->getElementalTabPath($eaRelationName);
            if (!$tabPath) {
                continue;
            }

            $types = $this->getOwner()->getElementalTypes($eaRelationName);
            if (!$types || !is_array($types) || count($types) < 1) {
                continue;
            }

            if (!$doReplaceContentField && $this->getOwner()->getDoElementalReplaceContentField($eaRelationName)) {
                $doReplaceContentField = true;
            }

            $area = $this->getOwner()->$eaRelationName();

            $relationCMSFields = null;
            if ($area->hasMethod('provideElementalAreaFields')) {
                $relationCMSFields = $area->provideElementalAreaFields($eaRelationName, $types);
            }

            if ($relationCMSFields === false) {
                continue;
            }

            if (!is_a($relationCMSFields, FieldList::class)) {
                $area = $this->getOwner()->$eaRelationName();
                $editor = ElementalAreaField::create(
                    $eaRelationName,
                    $area,
                    $types
                );
                $this->getOwner()->invokeWithExtensions(
                    'updateDefaultElementalAreaField',
                    $editor, $eaRelationName, $area, $types
                );
                $relationCMSFields = FieldList::create($editor);
            }

            $insertBefore = $this->getOwner()->getElementalInsertBefore($eaRelationName);
            foreach ($relationCMSFields as $relationCMSField) {
                if ($insertBefore) {
                    $fields->addFieldToTab($tabPath, $relationCMSField, $insertBefore);
                }
                else {
                    $fields->addFieldToTab($tabPath, $relationCMSField);
                }
            }
        }

        if ($doReplaceContentField) {
            $fields->replaceField(
                'Content',
                LiteralField::create('Content', '')
            );
        }
    }

    public function ensureElementalAreasExist($elementalAreaRelations)
    {
        $hasOnes = $this->getOwner()->hasOne();
        foreach ($elementalAreaRelations as $eaRelationship) {
            $areaID = $eaRelationship . 'ID';
            $eaClassName = $hasOnes[$eaRelationship];

            if (!$this->owner->$areaID || !($existing = $eaClassName::get()->byID($this->owner->$areaID)) || !$existing->exists()) {
                $area = $eaClassName::create();
                $area->OwnerClassName = get_class($this->owner);
                $area->write();
                $this->owner->$areaID = $area->ID;
            }
        }
        return $this->owner;
    }

    public function onBeforeWrite()
    {
        if (!$this->getOwner()->supportsElemental()) {
            return;
        }

        $relations = $this->getOwner()->getElementalRelations();
        $this->getOwner()->ensureElementalAreasExist($relations);

        $clearContentField = $this->getOwner()->getDoElementalClearContentField();
        if (!$clearContentField) {
            foreach ($relations as $relationName) {
                if ($this->getOwner()->getDoElementalClearContentField($relationName)) {
                    $clearContentField = true;
                    continue;
                }
            }
        }
        if ($clearContentField) {
            $this->getOwner()->Content = '';
        }
    }

    public function supportsElemental()
    {
        $doSupport = true;

        if ($this->getOwner()->hasMethod('includeElemental')) {
            $local = $this->getOwner()->includeElemental();
            if ($local !== null) {
                $doSupport = $local;
            }
        }

        $ignoredClasses = $this->getOwner()->getElementalIgnoredClasses();
        foreach ($ignoredClasses as $ignoredClass) {
            if (is_a($this->getOwner(), $ignoredClass)) {
                $doSupport = false;
            }
        }

        $this->getOwner()->invokeWithExtensions('updateSupportsElemental', $doSupport);
        return $doSupport;
    }

    public function getElementalIgnoredClasses()
    {
        $ignoredClasses = Config::inst()->get(static::class, 'ignored_classes');
        if ($ignoredClasses && is_string($ignoredClasses)) {
            $ignoredClasses = [$ignoredClasses];
        }
        if (!is_array($ignoredClasses)) {
            $ignoredClasses = [];
        }
        $this->getOwner()->invokeWithExtensions('updateElementalIgnoredClasses', $ignoredClasses);
        return $ignoredClasses;
    }

    public function getElementalRelationConfig($relationName)
    {
        $relationsConfig = $this->getOwner()->config()->get('elemental_relations');

        $config = null;
        if (is_array($relationsConfig) && count($relationsConfig) > 0) {

            if (isset($relationsConfig[$relationName])) {
                $config = $relationsConfig[$relationName];

                if (isset($config['allowed_elements']) || isset($config['disallowed_elements'])) {
                    if (isset($config['stop_element_inheritance'])) {
                        $stopInheritance = (bool) $config['stop_element_inheritance'];
                        if ($stopInheritance) {

                            $uninheritedConfig = $this->getOwner()->config()
                                ->get('elemental_relations', Config::UNINHERITED);

                            if (isset($uninheritedConfig[$relationName])) {

                                if (isset($config['allowed_elements'])) {
                                    unset($config['allowed_elements']);
                                }
                                if (isset($uninheritedConfig[$relationName]['allowed_elements'])) {
                                    $config['allowed_elements'] = $uninheritedConfig[$relationName]['allowed_elements'];
                                }

                                if (isset($config['disallowed_elements'])) {
                                    unset($config['disallowed_elements']);
                                }
                                if (isset($uninheritedConfig[$relationName]['disallowed_elements'])) {
                                    $config['disallowed_elements'] = $uninheritedConfig[$relationName]['disallowed_elements'];
                                }
                            }
                        }
                    }
                }
            }
        }
        $this->getOwner()->invokeWithExtensions('updateElementalRelationConfig', $config, $relationName);
        return $config;
    }

    public function getElementalTypes($relationName = null)
    {
        $types = [];

        $baseClass = $this->getOwner()->getElementalBaseClass($relationName);
        $availableClasses = ClassInfo::subclassesFor($baseClass);

        $config = $this->getOwner()->config();
        $stopInheritance = (bool) $config->get('stop_element_inheritance');

        $allowedClasses = null;
        if ($stopInheritance) {
            $allowedClasses = $config->get('allowed_elements', Config::UNINHERITED);
        }
        else {
            $allowedClasses = $config->get('allowed_elements');
        }
        if (is_array($allowedClasses)) {
            $availableClasses = $allowedClasses;
        }

        if ($stopInheritance) {
            $disallowedElements = $config->get('disallowed_elements', Config::UNINHERITED);
        }
        else {
            $disallowedElements = $config->get('disallowed_elements');
        }

        foreach ($availableClasses as $key => $availableClass) {
            $inst = $availableClass::singleton();

            if (is_array($disallowedElements)) {
                if (in_array($availableClass, $disallowedElements)) {
                    unset($availableClasses[$key]);
                    continue;
                }
            }

            if (!$inst->canCreate()) {
                unset($availableClasses[$availableClass]);
                continue;
            }

            if ($inst->hasMethod('canCreateElement') && !$inst->canCreateElement()) {
                unset($availableClasses[$availableClass]);
                continue;
            }
        }

        if ($relationName) {
            $relationConfig = $this->getOwner()->getElementalRelationConfig($relationName);

            if (is_array($relationConfig)) {

                $allowedClasses = null;
                if (isset($relationConfig['allowed_elements'])) {
                    $allowedClasses = $relationConfig['allowed_elements'];
                }
                if (is_array($allowedClasses)) {
                    foreach ($availableClasses as $key => $availableClass) {
                        if (!in_array($availableClass, $allowedClasses)) {
                            unset($availableClasses[$key]);
                        }
                    }
                }

                $disallowedClasses = null;
                if (isset($relationConfig['disallowed_elements'])) {
                    $disallowedClasses = $relationConfig['disallowed_elements'];
                }
                if (is_array($disallowedClasses)) {
                    foreach ($availableClasses as $key => $availableClass) {
                        if (in_array($availableClass, $disallowedClasses)) {
                            unset($availableClasses[$key]);
                        }
                    }
                }
            }
        }

        foreach ($availableClasses as $availableClass) {
            $inst = $availableClass::singleton();
            $types[$availableClass] = $inst->getType();
        }

        if ($config->get('sort_types_alphabetically') !== false) {
            asort($types);
        }

        if (isset($types[$baseClass])) {
            unset($types[$baseClass]);
        }

        $this->getOwner()->invokeWithExtensions('updateElementalTypes', $types, $relationName);
        return $types;
    }

    public function getDoElementalReplaceContentField($relationName = null)
    {
        $doReplace = (bool) $this->getOwner()->config()->get('elemental_replace_contentfield');
        if ($relationName) {
            $config = $this->getOwner()->getElementalRelationConfig($relationName);
            if ($config && isset($config['replace_contentfield'])) {
                $doReplace = (bool) $config['replace_contentfield'];
            }
        }
        $this->getOwner()->invokeWithExtensions('updateDoElementalReplaceContentField', $doReplace, $relationName);
        return $doReplace;
    }

    public function getDoElementalClearContentField($relationName = null)
    {
        $doClear = (bool) $this->getOwner()->config()->get('elemental_clear_contentfield');
        if ($relationName) {
            $doClear = false;
            $config = $this->getOwner()->getElementalRelationConfig($relationName);
            if ($config && isset($config['clear_contentfield'])) {
                $doClear = (bool) $config['clear_contentfield'];
            }
        }
        $this->getOwner()->invokeWithExtensions('updateDoElementalClearContentField');
        return $doClear;
    }

    public function getElementalBaseClass($relationName = null)
    {
        $class = $this->getOwner()->config()->get('elemental_base_class');
        if ($relationName) {
            $config = $this->getOwner()->getElementalRelationConfig($relationName);
            if ($config && isset($config['base_class'])) {
                $class = $config['base_class'];
            }
        }
        $this->getOwner()->invokeWithExtensions('updateElementalBaseClass', $class, $relationName);
        return $class;
    }

    public function getElementalTabPath($relationName = null)
    {
        $tabPath = $this->getOwner()->config()->get('elemental_tab_path');
        if ($relationName) {
            $config = $this->getOwner()->getElementalRelationConfig($relationName);
            if ($config && isset($config['tab_path'])) {
                $tabPath = $config['tab_path'];
            }
        }
        $this->getOwner()->invokeWithExtensions('updateElementalTabPath', $tabPath, $relationName);
        return $tabPath;
    }

    public function getElementalInsertBefore($relationName = null)
    {
        $insertBefore = $this->getOwner()->config()->get('elemental_insert_before');
        if ($relationName) {
            $config = $this->getOwner()->getElementalRelationConfig($relationName);
            if ($config && isset($config['insert_before'])) {
                $insertBefore = $config['insert_before'];
            }
        }
        $this->getOwner()->invokeWithExtensions('updateElementalInsertBefore', $insertBefore, $relationName);
        return $insertBefore;
    }
}
