<?php
// ----------------------------------------------------------------------------------
// Class: RDF_RDQL_Engine_MDB
// ----------------------------------------------------------------------------------
/**
 * This class performs as RDQL query on a Model_MDB.
 *
 * Provided an RDQL query parsed into an array of php variables and constraints
 * at first the engine generates an sql statement and queries the database for
 * tuples matching all patterns from the WHERE clause of the given RDQL query.
 * Subsequently the result set is is filtered with evaluated boolean expressions
 * from the AND clause of the given RDQL query.
 *
 * @version V0.7
 * @author Radoslaw Oldakowski <radol@gmx.de>
 * @package RDQL
 * @access public
 */

require_once 'RDF/RDQL/Engine.php';

class RDF_RDQL_Engine_MDB extends RDF_RDQL_Engine
{
    /**
     * Parsed query variables and constraints.
     *
     * @var array ['selectVars'][] = ?VARNAME
     *                   ['sources'][] = URI
     *                   ['patterns'][]['subject']['value'] = VARorURI
     *                                 ['predicate']['value'] = VARorURI
     *                                 ['object']['value'] = VARorURIorLiterl
     *                                           ['is_literal'] = boolean
     *                                           ['l_lang'] = string
     *                                           ['l_dtype'] = string
     *                   ['filters'][]['string'] = string
     *                                ['evalFilterStr'] = string
     *                                ['reqexEqExprs'][]['var'] = ?VARNAME
     *                                                  ['operator'] = (eq | ne)
     *                                                  ['regex'] = string
     *                                ['strEqExprs'][]['var'] = ?VARNAME
     *                                                ['operator'] = (eq | ne)
     *                                                ['value'] = string
     *                                                ['value_type'] = ('variable' | 'URI' | 'Literal')
     *                                                ['value_lang'] = string
     *                                                ['value_dtype'] = string
     *                                ['numExpr']['vars'][] = ?VARNAME
     *                          ( [] stands for an integer index - 0..N )
     * @access private
     */
    var $parsedQuery;

    /**
     * When an RDQL query is performed on a Model_MDB, in first step the engine searches
     * in database for triples matching the RDQL-WHERE clause. A recordSet is returned.
     * $rsIndexes maps select and filter variables to their corresponding indexes
     * in the returned recordSet.
     *
     * @var array [?VARNAME]['value'] = integer
     *                           ['nType'] = integer
     *                           ['l_lang'] = integer
     *                           ['l_dtype'] = integer
     * @access private
     */
    var $rsIndexes;


    /**
     * Perform an RDQL Query on the given Model_MDB.
     *
     * @param object Model_MDB $Model_MDB
     * @param array &$parsedQuery  (the same format as $this->parsedQuery)
     * @param boolean $returnNodes
     * @return array [][?VARNAME] = object Node  (if $returnNodes = TRUE)
     *       OR  array   [][?VARNAME] = string
     * @access public
     */
    function &queryModel(&$model, &$parsedQuery, $returnNodes = true)
    {
        $this->parsedQuery = &$parsedQuery;

        $sql = $this->generateSql($model->modelID);
        $recordSet = &$model->dbConn->queryAll($sql);
        $queryResult = $this->filterQueryResult($recordSet);

        if ($returnNodes) {
            return $this->toNodes($queryResult);
        } else {
            return $this->toString($queryResult);
        }
    }

    /**
     * Generate an SQL string to query the database for tuples matching all patterns
     * of $parsedQuery.
     *
     * @param integer $modelID
     * @return string
     * @access private
     */
    function generateSql($modelID)
    {
        $sql = $this->generateSql_SelectClause();
        $sql .= $this->generateSql_FromClause();
        $sql .= $this->generateSql_WhereClause($modelID);
        return $sql;
    }

    /**
     * Generate SQL SELECT clause.
     *
     * @return string
     * @throws PHPError
     * @access private
     */
    function generateSql_SelectClause()
    {
        $sql_select = 'SELECT';
        $index = 0;
        $this->rsIndexes = array();

        foreach ($this->parsedQuery['selectVars'] as $var) {
            $sql_select .= $this->_generateSql_SelectVar($index, $var);
        }

        if (isset($this->parsedQuery['filters'])) {
            foreach ($this->parsedQuery['filters'] as $filter) {
                // variables from numeric expressions
                foreach ($filter['numExprVars'] as $numVar) {
                    $sql_select .= $this->_generateSql_SelectVar($index, $numVar);
                }
                // variables from regex equality expressions
                foreach ($filter['regexEqExprs'] as $regexEqExpr) {
                    $sql_select .= $this->_generateSql_SelectVar($index, $regexEqExpr['var']);
                }
                // variables from string equality expressions
                foreach ($filter['strEqExprs'] as $strEqVar) {
                    $sql_select .= $this->_generateSql_SelectVar($index, $strEqVar['var']);
                }
            }
        }

        return rtrim($sql_select, ' , ');
    }

    /**
     * Generate SQL FROM clause
     *
     * @return string
     * @access private
     */
    function generateSql_FromClause()
    {
        $sql_from = ' FROM';
        foreach ($this->parsedQuery['patterns'] as $n => $v) {
            $sql_from .= ' statements s' . ($n + 1) . ' , ';
        }

        return rtrim($sql_from, ' , ');
    }

    /**
     * Generate an SQL WHERE clause
     *
     * @param integer $modelID
     * @return string
     * @access private
     */
    function generateSql_WhereClause($modelID)
    {
        $sql_where = ' WHERE';
        $count_patterns = count($this->parsedQuery['patterns']);
        foreach ($this->parsedQuery['patterns'] as $n => $pattern) {
            $sql_where .= ' s' .($n+1) .'.modelID=' .$modelID .' AND';
            foreach ($pattern as $key => $val_1) {
                if ($val_1['value'] && $val_1['value']{0}=='?') {
                    $sql_tmp = ' s' .($n+1) .'.' .$key .'=';
                     // find internal bindings
                    switch ($key) {
                    case 'subject':
                        if ($pattern['subject']['value'] == $pattern['predicate']['value']) {
                            $sql_where .= $sql_tmp .'s' .($n+1) .'.predicate AND';
                        } elseif ($pattern['subject']['value'] == $pattern['object']['value']) {
                            $sql_where .= $sql_tmp .'s' .($n+1) .'.object AND';
                        }
                        break;
                    case 'predicate':
                        if ($pattern['predicate']['value'] == $pattern['object']['value']) {
                            $sql_where .= $sql_tmp .'s' .($n+1) .'.object AND';
                        }
                     }
                     // find external bindings
                     for ($i=$n+1; $i<$count_patterns; $i++) {
                         foreach ($this->parsedQuery['patterns'][$i] as $key2 => $val_2) {
                            if ($val_1['value']==$val_2['value']) {
                                $sql_where .= $sql_tmp .'s' .($i+1) .'.' .$key2 .' AND';
                                break 2;
                            }
                         }
                     }
                 } else {
                    $sql_where .= ' s' .($n+1) .'.' .$key ."='" .$val_1['value'] ."' AND";
                    if ($key == 'object' && isset($val_1['is_literal'])) {
                        $sql_where .= ' s' .($n+1) .".object_is='l' AND";
                        $sql_where .= ' s' .($n+1) .".l_datatype='" .$val_1['l_dtype'] ."' AND";
                        // Lang tags only differentiate literals in rdf:XMLLiterals and plain literals.
                        // Therefore if a literal is datatyped ignore the language.
                        if ($val_1['l_dtype'] == NULL ||
                            $val_1['l_dtype'] == 'http://www.w3.org/2001/XMLSchema#string' ||
                            $val_1['l_dtype'] == 'http://www.w3.org/1999/02/22-rdf-syntax-ns#XMLLiteral')

                            $sql_where .= ' s' .($n+1) .".l_language='" .$val_1['l_lang'] ."' AND";
                      }
                }
           }
        }
        return rtrim($sql_where, ' AND');
    }

    /**
     * Filter tuples containing variables matching all patterns from the WHERE clause
     * of an RDQL query. As a result of a database query using ADOdb these tuples
     * are returned as an ADORecordSet object, which is then passed to this function.
     *
     * @param object ADORecordSet &$recordSet
     * @return array [][?VARNAME]['value']   = string
     *                              ['nType']   = string
     *                              ['l_lang']  = string
     *                              ['l_dtype'] = string
     * @access private
     */
    function filterQueryResult(&$recordSet)
    {
        $queryResult = array();

        if (isset($this->parsedQuery['filters'])) {
            for ($k=0,$l=count($recordSet); $k<$l; ++$k) {
                foreach ($this->parsedQuery['filters'] as $filter) {
                    $evalFilterStr = $filter['evalFilterStr'];
                    // evaluate regex equality expressions of each filter
                    foreach ($filter['regexEqExprs'] as $i => $expr) {
                        $value = $recordSet[$k][$this->rsIndexes[$expr['var']]['value']];
                        $match = array();
                        preg_match($expr['regex'], $value, $match);
                        $op = substr($expr['operator'], 0, 1);
                        if (($op != '!' && !isset($match[0]))
                            || ($op == '!' && isset($match[0]))
                        ) {
                            $evalFilterStr = str_replace("##RegEx_$i##", 'FALSE', $evalFilterStr);
                        } else {
                            $evalFilterStr = str_replace("##RegEx_$i##", 'TRUE', $evalFilterStr);
                        }
                    }
                    // evaluate string equality expressions
                    foreach ($filter['strEqExprs'] as $i => $expr) {
                        $exprBoolVal = 'FALSE';

                        switch ($expr['value_type']) {
                        case 'variable':
                            if (($value == $recordSet[$k][$this->rsIndexes[$expr['value']]['value']]
                                && $expr['operator'] == 'eq')
                                || ($value != $recordSet[$k][$this->rsIndexes[$expr['value']]['value']]
                                && $expr['operator'] == 'ne')
                            ) {
                                    $exprBoolVal = 'TRUE';
                            }
                            break;
                        case 'URI':
                            if (isset($this->rsIndexes[$expr['var']]['nType'])
                                && $recordSet[$k][$this->rsIndexes[$expr['var']]['nType']] == 'l'
                            ) {
                                if ($expr['operator'] == 'ne')
                                    $exprBoolVal = 'TRUE';
                                break;
                            }

                            if (($value == $expr['value']
                                && $expr['operator'] == 'eq')
                                || ($value != $expr['value']
                                && $expr['operator'] == 'ne')
                            ) {
                                $exprBoolVal = 'TRUE';
                            }
                            break;

                        case 'Literal':

                            if (!isset($this->rsIndexes[$expr['var']]['nType'])
                                || $recordSet[$k][$this->rsIndexes[$expr['var']]['nType']] != 'l'
                            ) {
                                if ($expr['operator'] == 'ne') {
                                    $exprBoolVal = 'TRUE';
                                }
                                break;
                            }

                            if ($value == $expr['value']
                                && $recordSet[$k][$this->rsIndexes[$expr['var']]['l_dtype']] == $expr['value_dtype']
                            ) {
                                $equal = true;
                                // Lang tags only differentiate literals in rdf:XMLLiterals and plain literals.
                                // Therefore if a literal is datatyped ignore the language tag.
                                if (
                                    ($expr['value_dtype'] == null
                                        || ($expr['value_dtype'] == 'http://www.w3.org/1999/02/22-rdf-syntax-ns#XMLLiteral')
                                        || ($expr['value_dtype'] == 'http://www.w3.org/2001/XMLSchema#string')
                                    ) && (($recordSet[$k][$this->rsIndexes[$expr['var']]['l_lang']] != $expr['value_lang']))
                                ) {
                                    $equal = false;
                                }
                            } else {
                                $equal = false;
                            }

                            if (($equal && $expr['operator'] == 'eq')
                                || (!$equal && $expr['operator'] == 'ne')
                            ) {
                                $exprBoolVal = 'TRUE';
                            } else {
                                $exprBoolVal = 'FALSE';
                            }
                        }

                        $evalFilterStr = str_replace("##strEqExpr_$i##", $exprBoolVal, $evalFilterStr);
                    }
                    // evaluate numerical expressions
                    foreach ($filter['numExprVars'] as $varName) {
                        $varValue = "'" .$recordSet[$k][$this->rsIndexes[$varName]['value']] ."'";
                        $evalFilterStr = str_replace($varName, $varValue, $evalFilterStr);
                    }
                    eval("\$filterBoolVal = $evalFilterStr; \$eval_filter_ok = TRUE;");
                    if (!isset($eval_filter_ok)) {
                        $errmsg = htmlspecialchars($filter['string']);
                        return RDF_RDQL::raiseError(RDF_RDQL_ERROROR_AND, null, null, $errmsg);
                    }

                    if (!$filterBoolVal) {
                        continue 2;
                    }
                }
                $queryResult[] = $this->_convertRsRowToQueryResultRow($recordSet[$k]);
            }
        } else {
            for ($k=0,$l=count($recordSet);$k<$l;++$k) {
                $queryResult[] = $this->_convertRsRowToQueryResultRow($recordSet[$k]);
            }
        }
        return $queryResult;
    }

    /**
     * Serialize variable values of $queryResult to string.
     *
     * @param array &$queryResult [][?VARNAME]['value']   = string
     *                                            ['nType']   = string
     *                                            ['l_lang']  = string
     *                                            ['l_dtype'] = string
     * @return array [][?VARNAME] = string
     * @access private
     */
    function toString(&$queryResult)
    {
        // if a result set is empty return only variable sames
        if (count($queryResult) == 0) {
            foreach ($this->parsedQuery['selectVars'] as $selectVar) {
                $res[0][$selectVar] = null;
            }
            return $res;
        }

        $res = array();
        foreach ($queryResult as $n => $var) {
            foreach ($var as $varname => $varProperties) {
                if ($varProperties['nType'] == 'r'
                    || $varProperties['nType'] == 'b'
                ) {
                    $res[$n][$varname] = '<' . $varProperties['value'] . '>';
                } else {
                    $res[$n][$varname] = '"' . $varProperties['value'] . '"';
                    if ($varProperties['l_lang'] != null) {
                        $res[$n][$varname] .= ' (xml:lang="' . $varProperties['l_lang'] . '")';
                    }
                    if ($varProperties['l_dtype'] != null) {
                        $res[$n][$varname] .= ' (rdf:datatype="' . $varProperties['l_dtype'] . '")';
                    }
                }
            }
        }
        return $res;
    }

    /**
     * Convert variable values of $queryResult to objects (Node).
     *
     * @param array &$queryResult [][?VARNAME]['value']   = string
     *                                            ['nType']   = string
     *                                            ['l_lang']  = string
     *                                            ['l_dtype'] = string
     * @return array [][?VARNAME] = object Node
     * @access private
     */
    function toNodes(&$queryResult)
    {
        // if a result set is empty return only variable sames
        if (count($queryResult) == 0) {
            foreach ($this->parsedQuery['selectVars'] as $selectVar) {
                $res[0][$selectVar] = null;
            }
            return $res;
        }

        $res = array();
        foreach ($queryResult as $n => $var) {
            foreach ($var as $varname => $varProperties) {
                if ($varProperties['nType'] == 'r') {
                    $res[$n][$varname] =& RDF_Resource::factory($varProperties['value']);
                } elseif ($varProperties['nType'] == 'b') {
                    $res[$n][$varname] =& RDF_BlankNode::factory($varProperties['value']);
                } else {
                    $res[$n][$varname] =& RDF_Literal::factory($varProperties['value'], $varProperties['l_lang']);
                    if ($varProperties['l_dtype'] != null)
                        $res[$n][$varname]->setDataType($varProperties['l_dtype']);
                }
            }
        }
        return $res;
    }

    /**
     * Generate a piece of an sql select statement for a variable.
     * Look first if the given variable is defined as a pattern object.
     * (So you can select the node type, literal lang and dtype)
     * If not found - look for subjects and select node label and type.
     * If there is no result either go to predicates.
     * Predicates are always resources therefore select only the node label.
     *
     * @param string $varName
     * @return string
     * @access private
     */
    function _generateSql_SelectVar(&$index, $varName)
    {
        $sql_select = '';

        if (array_key_exists($varName, $this->rsIndexes))
            return null;

        foreach ($this->parsedQuery['patterns'] as $n => $pattern) {
            if ($varName == $pattern['object']['value']) {
                // select the object label
                $sql_select .= " s" . ++$n . ".object as _" . ltrim($varName, "?") . " , ";
                $this->rsIndexes[$varName]['value'] = $index++;
                // select the node type
                $sql_select .= " s" . $n . ".object_is , ";
                $this->rsIndexes[$varName]['nType'] = $index++;
                // select the object language
                $sql_select .= " s" . $n . ".l_language , ";
                $this->rsIndexes[$varName]['l_lang'] = $index++;
                // select the object dtype
                $sql_select .= " s" . $n . ".l_datatype , ";
                $this->rsIndexes[$varName]['l_dtype'] = $index++;
    
                return $sql_select;
            }
        }

        foreach ($this->parsedQuery['patterns'] as $n => $pattern) {
            if ($varName == $pattern['subject']['value']) {
                // select the object label
                $sql_select .= " s" . ++$n . ".subject as _" . ltrim($varName, "?") . " , ";
                $this->rsIndexes[$varName]['value'] = $index++;
                // select the node type
                $sql_select .= " s" . $n . ".subject_is , ";
                $this->rsIndexes[$varName]['nType'] = $index++;
    
                return $sql_select;
            }
        }

        foreach ($this->parsedQuery['patterns'] as $n => $pattern) {
            if ($varName == $pattern['predicate']['value']) {
                // select the object label
                $sql_select .= " s" . ++$n . ".predicate as _" . ltrim($varName, "?") . " , ";
                $this->rsIndexes[$varName]['value'] = $index++;
    
                return $sql_select;
            }
        }
    }

    /**
     * Converts a single row of ADORecordSet->fields array to the format of
     * $queryResult array using pointers to indexes ($this->rsIndexes) in RecordSet->fields.
     *
     * @param array &$record [] = string
     * @return array [?VARNAME]['value']   = string
     *                            ['nType']   = string
     *                            ['l_lang']  = string
     *                            ['l_dtype'] = string
     * @access private
     */
    function _convertRsRowToQueryResultRow(&$record)
    {
        // return only select variables (without conditional variables from the AND clause)
        foreach ($this->parsedQuery['selectVars'] as $selectVar) {
            $resultRow[$selectVar]['value'] = $record[$this->rsIndexes[$selectVar]['value']];
            if (isset($this->rsIndexes[$selectVar]['nType'])) {
                $resultRow[$selectVar]['nType'] = $record[$this->rsIndexes[$selectVar]['nType']];
            // is a predicate then
            } else {
                $resultRow[$selectVar]['nType'] = 'r';
            }

            if ($resultRow[$selectVar]['nType'] == 'l') {
                $resultRow[$selectVar]['l_lang'] = $record[$this->rsIndexes[$selectVar]['l_lang']];
                $resultRow[$selectVar]['l_dtype'] = $record[$this->rsIndexes[$selectVar]['l_dtype']];
            }
        }
        return $resultRow;
    }
} // end: Class RDQLDBEngine

?>