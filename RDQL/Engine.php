<?php
// ----------------------------------------------------------------------------------
// Class: RDF_RDQL_Engine
// ----------------------------------------------------------------------------------
/**
 * Some general methods common for RDQLMemEngine and RDQLDBEngine
 *
 * @version V0.7
 * @author Radoslaw Oldakowski <radol@gmx.de>
 * @package RDQL
 * @access public
 */

class RDF_RDQL_Engine extends RDF_Object
{
    /**
     * Prints a query result as HTML table.
     * You can change the colors in the configuration file.
     *
     * @param array $queryResult [][?VARNAME] = object Node
     * @access private
     */
    function writeQueryResultAsHtmlTable($queryResult)
    {
        if (current($queryResult[0]) == null) {
            echo 'no match<br>';
            return;
        }

        echo '<table border="1" cellpadding="3" cellspacing="0"><tr><td><b>No.</b></td>';
        foreach ($queryResult[0] as $varName => $value)
        echo "<td align='center'><b>$varName</b></td>";
        echo '</tr>';

        foreach ($queryResult as $n => $var) {
            echo '<tr><td width="20" align="right">' . ($n + 1) . '.</td>';
            foreach ($var as $varName => $value) {
                echo RDF_INDENTATION . RDF_INDENTATION . '<td bgcolor="';
                echo RDF_Util::chooseColor($value);
                echo '">';
                echo '<p>';

                $lang = null;
                $dtype = null;
                if (is_a($value, 'RDF_Literal')) {
                    if ($value->getLanguage() != null) {
                        $lang = ' <b>(xml:lang="' . $value->getLanguage() . '") </b> ';
                    }
                    if ($value->getDatatype() != null) {
                        $dtype = ' <b>(rdf:datatype="' . $value->getDatatype() . '") </b> ';
                    }
                }
                echo RDF_Util::getNodeTypeName($value) . $value->getLabel() . $lang . $dtype . '</p>';
            }
            echo '</tr>';
        }
        echo '</table>';
    }
} // end: Class RDQLEngine

?>