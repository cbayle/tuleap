<?php
/**
 * Copyright (c) Enalean, 2013. All Rights Reserved.
 *
 * This file is a part of Tuleap.
 *
 * Tuleap is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * Tuleap is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Tuleap; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 */

/*
 * This class ensures that a XML document is valid with a given RNG file
 */

class XmlValidator {

    /**
     * This function valids our XML content with a given RNG file
     *
     * @return boolean True if valid, else False
     */
    public function nodeIsValid(SimpleXMLElement $xml_node, $rng_path) {
        $dom = $this->simpleXmlElementToDomDocument($xml_node);

        $xml_security = new XML_Security();
        $xml_security->enableExternalLoadOfEntities();
        $is_valid = $dom->relaxNGValidate($rng_path);
        $xml_security->disableExternalLoadOfEntities();

        return $is_valid;
    }

    /**
     * Validate XML using Jing to get "meaningful" error messages
     *
     * @param SimpleXMLElement $xml_node
     * @param String           $rng_path
     *
     * @return String[]
     */
    public function getValidationErrors(SimpleXMLElement $xml_node, $rng_path) {
        $dom = $this->simpleXmlElementToDomDocument($xml_node);
        $indent   = $GLOBALS['codendi_utils_prefix'] .'/xml/indent.xsl';
        $jing     = $GLOBALS['codendi_utils_prefix'] .'/xml/jing.jar';
        $temp     = tempnam($GLOBALS['tmp_dir'], 'xml');
        $xml_file = tempnam($GLOBALS['tmp_dir'], 'xml_src_');
        file_put_contents($xml_file, $dom->saveXML());
        $cmd_indent = "xsltproc -o $temp $indent $xml_file";
        `$cmd_indent`;

        $output = array();
        $cmd_valid = "java -jar $jing $rng_path $temp";
        exec($cmd_valid, $output);
        unlink($temp);
        unlink($xml_file);
        return $output;
    }

    /**
     * Create a dom document based on a SimpleXMLElement
     *
     * @param SimpleXMLElement $xml_element
     *
     * @return \DOMDocument
     */
    private function simpleXmlElementToDomDocument(SimpleXMLElement $xml_element) {
        $dom = new DOMDocument("1.0", "UTF-8");
        $dom_element = $dom->importNode(dom_import_simplexml($xml_element), true);
        $dom->appendChild($dom_element);
        return $dom;
    }
}

?>
