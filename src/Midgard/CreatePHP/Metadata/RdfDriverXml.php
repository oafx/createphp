<?php
/**
 * @copyright CONTENT CONTROL GmbH, http://www.contentcontrol-berlin.de
 * @author David Buchmann <david@liip.ch>
 * @license Dual licensed under the MIT (MIT-LICENSE.txt) and LGPL (LGPL-LICENSE.txt) licenses.
 * @package Midgard.CreatePHP
 */

namespace Midgard\CreatePHP\Metadata;

use Midgard\CreatePHP\RdfMapperInterface;
use Midgard\CreatePHP\NodeInterface;
use Midgard\CreatePHP\Entity\Controller as Type;
use Midgard\CreatePHP\Entity\Property as PropertyDefinition;
use Midgard\CreatePHP\Entity\Collection as CollectionDefinition;
use Midgard\CreatePHP\Type\TypeInterface;
use Midgard\CreatePHP\Type\PropertyDefinitionInterface;
use Midgard\CreatePHP\Type\CollectionDefinitionInterface;
use Midgard\CreatePHP\Helper\NamespaceHelper;

/**
 * This driver loads rdf mappings from xml files
 *
 * <type
 *      xmlns:sioc="http://rdfs.org/sioc/ns#"
 *      xmlns:dcterms="http://purl.org/dc/terms/"
 *      xmlns:skos="http://www.w3.org/2004/02/skos/core#"
 *      typeof="sioc:Thread"
 * >
 *      <config key="my" value="value"/>
 *      <rev>dcterms:partOf</rev>
 *      <children>
 *          <property property="dcterms:title" identifier="title" tag-name="h2"/>
 *          <collection rel="skos:related" identifier="tags" tag-name="ul">
 *              <config key="my" value="value"/>
 *              <attribute key="class" value="tags"/>
 *          </collection>
 *          <!-- the rev attribute for a collection is only needed if children
 *               support more than one possible rev attribute -->
 *          <collection rel="dcterms:hasPart" rev="dcterms:partOf" identifier="posts" tag-name="ul">
 *              <childtype>sioc:Post</childtype>
 *          </collection>
 *      </children>
 * </type>
 *
 * @package Midgard.CreatePHP
 */
class RdfDriverXml extends AbstractRdfDriver
{
    private $directories = array();

    /**
     * @param array $directories list of directories to look for rdf metadata
     */
    public function __construct($directories)
    {
        $this->directories = $directories;
    }

    /**
     * Return the NodeInterface wrapping a type for the specified class
     *
     * @param string $name
     * @param RdfMapperInterface $mapper
     *
     * @return \Midgard\CreatePHP\NodeInterface the type if found
     * @throws \Midgard\CreatePHP\Metadata\TypeNotFoundException
     */
    public function loadType($name, RdfMapperInterface $mapper, RdfTypeFactory $typeFactory)
    {
        $xml = $this->getXmlDefinition($name);
        if (null == $xml) {
            throw new TypeNotFoundException('No RDFa mapping found for "' . $name . '" (looked for "'.$this->buildFileName($name).'")');
        }

        $type = $this->createType($mapper, $this->getConfig($xml));
        foreach ($xml->rev as $rev) {
            $type->addRev((string) $rev);
        }

        if ($type instanceof NodeInterface) {
            $this->parseNodeInfo($type, $xml);
        }

        foreach ($xml->getDocNamespaces(true) as $prefix => $uri) {
            $type->setVocabulary($prefix, $uri);
        }
        if (isset($xml['typeof'])) {
            $type->setRdfType($xml['typeof']);
        }

        if (isset($xml['vocab'])) {
            $type->setAttribute('vocab' , $xml['vocab']);
        }

        if (isset($xml['prefix'])) {
            $type->setAttribute('prefix', $xml['prefix']);

            $prefix = explode(': ',$xml['prefix'])[0];
            $url = explode(': ',$xml['prefix'])[1];
            $type->setVocabulary($prefix, $url);
        }
        
        $add_default_vocabulary = false;
        foreach($xml->children->children() as $child) {
            $c = $this->createChild($child->getName(), $child['identifier'], $child, $typeFactory);
            $this->parseChild($c, $child, $child['identifier'], $add_default_vocabulary);
            $type->{$child['identifier']} = $c;
        }

        if ($add_default_vocabulary) {
            $type->setVocabulary(self::DEFAULT_VOCABULARY_PREFIX, self::DEFAULT_VOCABULARY_URI);
        }

        return $type;
    }

    /**
     * Build the attributes from the property|rel field and any custom attributes
     *
     * @param mixed $child a property definition, collection definition or node
     * @param \ArrayAccess $childData the child to read field from
     * @param string $field the field to be read, property for properties, rel for collections
     * @param string $identifier to be used in case there is no property field in $child
     * @param boolean $add_default_vocabulary flag to tell whether to add vocabulary for
     *      the default namespace.
     *
     * @return array properties
     */
    protected function parseChild($child, $childData, $identifier, &$add_default_vocabulary)
    {
        if ($child instanceof PropertyDefinitionInterface) {
            /** @var $child PropertyDefinitionInterface */
            $child->setProperty($this->buildInformation($childData, $identifier, 'property', $add_default_vocabulary));
        } else {
            /** @var $child CollectionDefinitionInterface */
            $child->setRel($this->buildInformation($childData, $identifier, 'rel', $add_default_vocabulary));
            $child->setRev($this->buildInformation($childData, $identifier, 'rev', $add_default_vocabulary));
            foreach ($childData->childtype as $childtype) {
                $expanded = NamespaceHelper::expandNamespace((string) $childtype, $childData->getDocNamespaces(true));
                $child->addTypeName($expanded);
            }
        }
        if ($child instanceof NodeInterface) {
            $this->parseNodeInfo($child, $childData);
        }
    }

    /**
     * {@inheritDoc}
     *
     * For XML, we get the configuration from <config key="x" value="y"/> elements.
     *
     * @param \SimpleXMLElement $xml the element maybe having config children
     *
     * @return array built from the config children of the element
     */
    protected function getConfig($xml, $field='config')
    {
        $config = array();
        foreach ($xml->$field as $c) {
            $config[(string)$c['key']] = (string)$c['value'];
        }
        return $config;
    }

    protected function getAttributes($xml)
    {
        return $this->getConfig($xml, 'attribute');
    }

    /**
     * Load the xml information from the file system, if a matching file is
     * found in any of the configured directories.
     *
     * @param $className
     *
     * @return \SimpleXMLElement|null the definition or null if none found
     */
    protected function getXmlDefinition($className)
    {
        $filename = $this->buildFileName($className);
        foreach ($this->directories as $dir) {
            if (file_exists($dir . DIRECTORY_SEPARATOR . $filename)) {
                return simplexml_load_file($dir . DIRECTORY_SEPARATOR . $filename);
            }
        }
        return null;
    }

    /**
     * Determine the filename from the class name
     *
     * @param string $className the fully namespaced class name
     *
     * @return string the filename for which to look
     */
    protected function buildFileName($className)
    {
        return str_replace('\\', '.', $className) . '.xml';
    }
    protected function buildClassName($filename)
    {
        return str_replace('.', '\\', substr($filename, 0, -4));
    }

    /**
     * {@inheritDoc}
     */
    public function getAllNames()
    {
        $classes = array();
        foreach ($this->directories as $dir) {
            foreach (glob($dir . DIRECTORY_SEPARATOR . '*.xml') as $file) {
                $xml = simplexml_load_file($file);
                $namespaces = $xml->getDocNamespaces();

                $type = NamespaceHelper::expandNamespace($xml['typeof'], $namespaces);
                $classes[$type] = $this->buildClassName(basename($file));
            }
        }
        return $classes;
    }
}
