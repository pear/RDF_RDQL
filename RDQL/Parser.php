<?php
// ----------------------------------------------------------------------------------
// Class: RDF_RDQL_Parser
// ----------------------------------------------------------------------------------
/**
 * This class contains methods for parsing an RDQL query string into PHP variables.
 * The output of the RDQLParser is an array with variables and constraints
 * of each query clause (Select, From, Where, And, Using).
 * To perform an RDQL query this array has to be passed to the RDQLEngine.
 *
 * @version  V0.7
 * @author   Radoslaw Oldakowski <radol@gmx.de>
 *
 * @package rdql
 * @access public
 */


class RDF_RDQL_Parser extends RDF_Object {
/**
 * Parsed query variables and constraints.
 * { } are only used within the parser class and are not returned as parsed query.
 * ( [] stands for an integer index - 0..N )  
 *
 * @var     array   ['selectVars'][] = ?VARNAME
 *                  ['sources'][]{['value']} = URI | QName
 *                               {['is_qname'] = boolean}
 *                  ['patterns'][]['subject']['value'] = VARorURI
 *                                          {['is_qname'] = boolean}
 *                                ['predicate']['value'] = VARorURI
 *                                            {['is_qname'] = boolean}
 *                                ['object']['value'] = VARorURIorLiterl
 *                                         {['is_qname'] = boolean} 
 *                                          ['is_literal'] = boolean
 *                                          ['l_lang'] = string
 *                                          ['l_dtype'] = string
 *                                         {['l_dtype_is_qname'] = boolean}
 *                  ['filters'][]['string'] = string
 *                               ['evalFilterStr'] = string
 *                               ['reqexEqExprs'][]['var'] = ?VARNAME
 *                                                 ['operator'] = (eq | ne)
 *                                                 ['regex'] = string
 *                               ['strEqExprs'][]['var'] = ?VARNAME
 *                                               ['operator'] = (eq | ne)
 *                                               ['value'] = string
 *                                               ['value_type'] = ('variable' | 'URI' | 'QName' | 'Literal')
 *                                               ['value_lang'] = string
 *                                               ['value_dtype'] = string
 *                                              {['value_dtype_is_qname'] = boolean}
 *                               ['numExpr']['vars'][] = ?VARNAME
 *                 {['ns'][PREFIX] = NAMESPACE}    

     * @access private
     */
    var $parsedQuery;

    /**
     * Query string divided into a sequence of tokens.
     * A token is either: ' ' or "\n" or "\r" or "\t" or ',' or '(' or ')'
     * or a string containing any characters except from the above.
     *
     * @var array
     * @access private
     */
    var $tokens;

    /**
     * Parse the given RDQL query string and return an array with query variables and constraints.
     *
     * @param string $queryString
     * @return array $this->parsedQuery
     * @access public
     */
    function &parseQuery($queryString)
    {
        $cleanQueryString = $this->removeComments($queryString);
        $this->tokenize($cleanQueryString);
        $this->startParsing();
        if ($this->parsedQuery['selectVars'][0] == '*') {
            $this->parsedQuery['selectVars'] = $this->findAllQueryVariables();
        } else {
            $this->_checkSelectVars();
        }
        $this->replaceNamespacePrefixes();

        return $this->parsedQuery;
    }

    /**
     * Remove comments from the passed query string.
     *
     * @param string $query
     * @return string
     * @throws PHPError
     * @access private
     */
    function removeComments($query)
    {
        $last = strlen($query)-1;
        $query .= ' ';
        $clean = '';
        for ($i = 0; $i <= $last; $i++) {
            // don't search for comments inside a 'literal'@lang^^dtype or "literal"@lang^^dtype
            if ($query{$i} == "'" || $query{$i} == '"') {
                $quotMark = $query{$i};
                do {
                    $clean .= $query{$i++};
                } while ($i < $last && $query{$i} != $quotMark);
                $clean .= $query{$i};
                // language
                if ($query{$i+1} == '@') {
                    do {
                        if ($query{$i+1} == '^' && $query{$i+2} == '^') {
                            break;
                        }
                        $clean .= $query{++$i};
                    } while ($i < $last && $query{$i} != ' ' && $query{$i} != "\t"
                        && $query{$i} != "\n" && $query{$i} != "\r");
                }
                // datatype
                if ($query{$i+1} == '^' && $query{$i+2} == '^') {
                    do {
                        $clean .= $query{++$i};
                    } while ($i < $last && $query{$i} != ' ' && $query{$i} != "\t"
                        && $query{$i} != "\n" && $query{$i} != "\r");
                }
                // don't search for comments inside an <URI> either
            } elseif ($query{$i} == '<') {
                do {
                    $clean .= $query{$i++};
                } while ($i < $last && $query{$i} != '>');
                $clean .= $query{$i};
            } elseif ($query{$i} == '/') {
                // clear: // comment
                if ($i < $last && $query{$i+1} == '/') {
                    while ($i < $last && $query{$i} != "\n" && $query{$i} != "\r") {
                        ++$i;
                    }
                    $clean .= ' ';
                    // clear: /*comment*/
                } elseif ($i < $last-2 && $query{$i+1} == '*') {
                    $i += 2;
                    while ($i < $last && ($query{$i} != '*' || $query{$i+1} != '/')) {
                        ++$i;
                    }
                    if ($i >= $last && ($query{$last-1} != '*' || $query{$last} != '/')) {
                        $errmsg = "unterminated comment - '*/' missing";
                        return RDF_RDQL::raiseError(RDF_RDQL_ERROR_SYNTAX, null, null, $errmsg);
                    }
                    ++$i;
                } else {
                    $clean .= $query{$i};
                }
            } else {
                $clean .= $query{$i};
            }
        }
        return $clean;
    }

    /**
     * Divide the query string into tokens.
     * A token is either: ' ' or "\n" or "\r" or '\t' or ',' or '(' or ')'
     * or a string containing any character except from the above.
     *
     * @param string $queryString
     * @access private
     */
    function tokenize($queryString)
    {
        $queryString = trim($queryString, " \r\n\t");
        $specialChars = array (" ", "\t", "\r", "\n", ",", "(", ")");
        $len = strlen($queryString);
        $this->tokens[0] = '';
        $n = 0;

        for ($i = 0; $i < $len; ++$i) {
            if (!in_array($queryString{$i}, $specialChars)) {
                $this->tokens[$n] .= $queryString{$i};
            } else {
                if ($this->tokens[$n] != '') {
                    ++$n;
                }
                $this->tokens[$n] = $queryString{$i};
                $this->tokens[++$n] = '';
            }
        }
    }

    /**
     * Start parsing of the tokenized query string.
     *
     * @access private
     */
    function startParsing()
    {
        $this->parseSelect();
    }

    /**
     * Parse the SELECT clause of an RDQL query.
     * When the parsing of the SELECT clause is finished, this method will call
     * a suitable method to parse the subsequent clause.
     *
     * @throws PhpError
     * @access private
     */
    function parseSelect()
    {
        $this->_clearWhiteSpaces();
        // Check if the queryString contains a "SELECT" token
        if (strcasecmp('SELECT', current($this->tokens))) {
            $errmsg = current($this->tokens) . "' - SELECT keyword expected";
            return RDF_RDQL::raiseError(RDF_RDQL_ERROR_SELECT, null, null, $errmsg);
        }
        unset($this->tokens[key($this->tokens)]);
        $this->_clearWhiteSpaces();
        // Parse SELECT *
        if (current($this->tokens) == '*') {
            unset($this->tokens[key($this->tokens)]);
            $this->parsedQuery['selectVars'][0] = '*';
            $this->_clearWhiteSpaces();
            if (strcasecmp('FROM', current($this->tokens))
                && strcasecmp('SOURCE', current($this->tokens))
                && strcasecmp('WHERE', current($this->tokens))
            ) {
                $errmsg = htmlspecialchars(current($this->tokens)) .
                    ' - SOURCE or WHERE clause expected';
                return RDF_RDQL::raiseError(RDF_RDQL_ERROR_SYNTAX, null, null, $errmsg);
            }
        }
        // Parse SELECT ?Var (, ?Var)*
        $commaExpected = false;
        $comma = false;
        while (current($this->tokens) != null) {
            $k = key($this->tokens);
            $token = $this->tokens[$k];

            switch ($token) {
            case ',':
                if (!$commaExpected) {
                    $errmsg = 'unexpected comma';
                    return RDF_RDQL::raiseError(RDF_RDQL_ERROR_SELECT, null, null, $errmsg);
                }
                $comma = true;
                $commaExpected = false;
                break;
            case '(':
            case ')':
                $errmsg = "'$token' - illegal input";
                return RDF_RDQL::raiseError(RDF_RDQL_ERROR_SELECT, null, null, $errmsg);
                break;
            default :
                if (!strcasecmp('FROM', $token) || !strcasecmp('SOURCE', $token)) {
                    if ($comma) {
                        $errmsg = 'unexpected comma';
                        return RDF_RDQL::raiseError(RDF_RDQL_ERROR_SELECT, null, null, $errmsg);
                    }
                    unset($this->tokens[$k]);
                    return $this->parseFrom();
                } elseif (!strcasecmp('WHERE', $token) && !$comma) {
                    if ($comma) {
                        $errmsg = 'unexpected comma';
                        return RDF_RDQL::raiseError(RDF_RDQL_ERROR_SELECT, null, null, $errmsg);
                    }
                    unset($this->tokens[$k]);
                    return $this->parseWhere();
                }
                if ($token{0} == '?') {
                    $this->parsedQuery['selectVars'][] = $this->_validateVar($token, 'SELECT');
                    $commaExpected = true;
                    $comma = false;
                } else {
                    $errmsg = "'$token' - '?' missing";
                    return RDF_RDQL::raiseError(RDF_RDQL_ERROR_SELECT, null, null, $errmsg);
                }
            }
            unset($this->tokens[$k]);
            $this->_clearWhiteSpaces();
        }
        $errmsg = 'WHERE clause missing';
        return RDF_RDQL::raiseError(RDF_RDQL_ERROR_WHERE, null, null, $errmsg);
    }

    /**
     * Parse the FROM/SOURCES clause of an RDQL query
     * When the parsing of this clause is finished, parseWhere() will be called.
     *
     * @throws PhpError
     * @access private
     */
    function parseFrom()
    {
        $comma = false;
        $commaExpected = false;
        $i = -1;
        while (current($this->tokens) != null) {
            $this->_clearWhiteSpaces();
            if (!strcasecmp('WHERE', current($this->tokens))
                && count($this->parsedQuery['sources']) != 0
            ) {
                if ($comma) {
                    $errmsg = 'unexpected comma';
                    return RDF_RDQL::raiseError(RDF_RDQL_ERROR_SELECT, null, null, $errmsg);
                }
                unset($this->tokens[key($this->tokens)]);
                return $this->parseWhere();
            }
            if (current($this->tokens) == ',') {
                if ($commaExpected) {
                    $comma = true;
                    $commaExpected = false;
                    unset($this->tokens[key($this->tokens)]);
                } else {
                    $errmsg = 'unecpected comma';
                    return RDF_RDQL::raiseError(RDF_RDQL_ERROR_SOURCE, null, null, $errmsg);
                }
            } else {
                $token = current($this->tokens);
                $this->parsedQuery['sources'][++$i]['value'] = $this->_validateURI($token, RDF_RDQL_ERROR_SOURCE);
                if ($token{0} != '<') {
                    $this->parsedQuery['sources'][$i]['is_qname'] = TRUE;
                }
                $commaExpected = TRUE;
                $comma = FALSE;
            }
        }
        $errmsg = 'WHERE clause missing';
        return RDF_RDQL::raiseError(RDF_RDQL_ERROR_WHERE, null, null, $errmsg);
    }

    /**
     * *'
     * Parse the WHERE clause of an RDQL query.
     * When the parsing of the WHERE clause is finished, this method will call
     * a suitable method to parse the subsequent clause if provided.
     *
     * @throws PhpError
     * @access private
     */
    function parseWhere()
    {
        $comma = false;
        $commaExpected = false;
        $i = 0;

        do {
            $this->_clearWhiteSpaces();
            if (!strcasecmp('AND', current($this->tokens))
                && count($this->parsedQuery['patterns']) != 0
            ) {
                if ($comma) {
                    $errmsg = 'unexpected comma';
                    return RDF_RDQL::raiseError(RDF_RDQL_ERROR_WHERE, null, null, $errmsg);
                }
                unset($this->tokens[key($this->tokens)]);
                return $this->parseAnd();
            } elseif (!strcasecmp('USING', current($this->tokens))
                && count($this->parsedQuery['patterns']) != 0
            ) {
                if ($comma) {
                    $errmsg = 'unexpected comma';
                    return RDF_RDQL::raiseError(RDF_RDQL_ERROR_WHERE, null, null, $errmsg);
                }
                unset($this->tokens[key($this->tokens)]);
                return $this->parseUsing();
            }

            if (current($this->tokens) == ',') {
                $comma = true;
                $this->_checkComma($commaExpected, 'WHERE');
            } else {
                if (current($this->tokens) != '(') {
                    $errmsg = current($this->tokens) . "' - '(' expected";
                    return RDF_RDQL::raiseError(RDF_RDQL_ERROR_WHERE, null, null, $errmsg);
                }
                unset($this->tokens[key($this->tokens)]);
                $this->_clearWhiteSpaces();

                $this->parsedQuery['patterns'][$i]['subject'] =
                    $this->_validateVarUri(current($this->tokens));
                $this->_checkComma(true, 'WHERE');
                $this->parsedQuery['patterns'][$i]['predicate'] =
                    $this->_validateVarUri(current($this->tokens));
                $this->_checkComma(true, 'WHERE');
                $this->parsedQuery['patterns'][$i++]['object'] =
                    $this->_validateVarUriLiteral(current($this->tokens));
                $this->_clearWhiteSpaces();

                if (current($this->tokens) != ')') {
                    $errmsg = current($this->tokens) . "' - ')' expected";
                    return RDF_RDQL::raiseError(RDF_RDQL_ERROR_WHERE, null, null, $errmsg);
                }
                unset($this->tokens[key($this->tokens)]);
                $this->_clearWhiteSpaces();
                $commaExpected = true;
                $comma = false;
            }
        } while (current($this->tokens) != null);

        if ($comma) {
            $errmsg = 'unexpected comma';
            return RDF_RDQL::raiseError(RDF_RDQL_ERROR_WHERE, null, null, $errmsg);
        }
    }

    /**
     * Parse the AND clause of an RDQL query
     *
     * @throws PhpError
     * @access private
     * @toDo clear comments
     */
    function parseAnd()
    {
        $this->_clearWhiteSpaces();
        $n = 0;
        $filterStr = '';

        while (current($this->tokens) != null) {
            $k = key($this->tokens);
            $token = $this->tokens[$k];

            if (!strcasecmp('USING', $token)) {
                $this->parseFilter($n, $filterStr);
                unset($this->tokens[$k]);
                return $this->parseUsing();
            } elseif ($token == ',') {
                $this->parseFilter($n, $filterStr);
                $filterStr = '';
                $token = '';
                ++$n;
            }
            $filterStr .= $token;
            unset($this->tokens[$k]);
        }
        $this->parseFilter($n, $filterStr);
    }

    /**
     * Parse the USING clause of an RDQL query
     *
     * @throws PhpError
     * @access private
     */
    function parseUsing()
    {
        $commaExpected = false;
        $comma = false;

        do {
            $this->_clearWhiteSpaces();
            if (current($this->tokens) == ',') {
                $comma = true;
                $this->_checkComma($commaExpected, 'USING');
            } else {
                $prefix = $this->_validatePrefix(current($this->tokens));
                $this->_clearWhiteSpaces();

                if (strcasecmp('FOR', current($this->tokens))) {
                    $errmsg = "keyword 'FOR' missing in the namespace declaration";
                    return RDF_RDQL::raiseError(RDF_RDQL_ERROR_USING, null, null, $errmsg);
                }
                unset($this->tokens[key($this->tokens)]);
                $this->_clearWhiteSpaces();

                $this->parsedQuery['ns'][$prefix] =
                    $this->_validateUri(current($this->tokens), 'USING');
                $this->_clearWhiteSpaces();
                $commaExpected = true;
                $comma = false;
            }
        } while (current($this->tokens) != null);

        if ($comma) {
            $errmsg = 'unexpected comma';
            return RDF_RDQL::raiseError(RDF_RDQL_ERROR_WHERE, null, null, $errmsg);
        }
    }

    /**
     * Check if a filter from the AND clause contains an equal number of '(' and ')'
     * and parse filter expressions.
     *
     * @param integer $n
     * @param string $filter
     * @throws PHPError
     * @access private
     */
    function parseFilter($n, $filter)
    {
        if ($filter == null) {
            $errmsg = 'unexpected comma';
            return RDF_RDQL::raiseError(RDF_RDQL_ERROR_AND, null, null, $errmsg);
        }
        $paren = substr_count($filter, '(') - substr_count($filter, ')');
        if ($paren != 0) {
            if ($paren > 0) {
                $errmsg = "'{htmlspecialchars($filter)}' - ')' missing ";
            } elseif ($paren < 0) {
                $errmsg = "'{htmlspecialchars($filter)}' - too many ')' ";
            }
            return RDF_RDQL::raiseError(RDF_RDQL_ERROR_AND, null, null, $errmsg);
        }

        $this->parsedQuery['filters'][$n] = $this->parseExpressions($filter);
    }

    /**
     * Parse expressions inside the passed filter:
     * 1)  regex equality expressions:    ?var [~~ | =~ | !~ ] REG_EX
     * 2a) string equality expressions:   ?var  [eq | ne] "literal"@lang^^dtype.
     * 2b) string equality expressions:   ?var [eq | ne] <URI> or ?var [eq | ne] prefix:local_name
     * 3)  numerical expressions: e.q.    (?var1 - ?var2)*4 >= 20
     *
     * In cases 1-2 parse each expression of the given filter into an array of variables.
     * For each parsed expression put a place holder (e.g. ##RegEx_1##) into the filterStr.
     * The RDQLengine will then replace each place holder with the outcomming boolean value
     * of the corresponding expression.
     * The remaining filterStr contains only numerical expressions and place holders.
     *
     * @param string $filteStr
     * @return array ['string'] = string
     *                   ['evalFilterStr'] = string
     *                   ['reqexEqExprs'][]['var'] = ?VARNAME
     *                                     ['operator'] = (eq | ne)
     *                                     ['regex'] = string
     *                   ['strEqExprs'][]['var'] = ?VARNAME
     *                                  ['operator'] = (eq | ne)
     *                                  ['value'] = string
     *                                  ['value_type'] = ('variable' | 'URI' 'QName' | | 'Literal')
     *                                  ['value_lang'] = string
     *                                  ['value_dtype'] = string
     *                                  ['value_dtype_is_qname'] = boolean
     *                   ['numExpr']['vars'][] = ?VARNAME
     * @access private
     */
    function parseExpressions($filterStr)
    {
        $parsedFilter['string'] = $filterStr;
        $parsedFilter['regexEqExprs'] = array();
        $parsedFilter['strEqExprs'] = array();
        $parsedFilter['numExprVars'] = array();
        // parse regex string equality expressions, e.g. ?x ~~ !//foo.com/r!i
        $reg_ex  = "/(\?[a-zA-Z0-9_]+)\s+([~!=]~)\s+(['|\"])?([^\s'\"]+)(['|\"])?/";
        $eqExprs = array();
        preg_match_all($reg_ex, $filterStr, $eqExprs);
        foreach ($eqExprs[0] as $i => $eqExpr) {
            $this->_checkRegExQuotation($filterStr, $eqExprs[3][$i], $eqExprs[5][$i]);
            $parsedFilter['regexEqExprs'][$i]['var'] = $this->_isDefined($eqExprs[1][$i]);
            $parsedFilter['regexEqExprs'][$i]['operator'] = $eqExprs[2][$i];
            $parsedFilter['regexEqExprs'][$i]['regex'] = $eqExprs[4][$i];

            $filterStr = str_replace($eqExpr, " ##RegEx_$i## ", $filterStr);
        }
        // parse ?var  [eq | ne] "literal"@lang^^dtype
        $reg_ex  = "/(\?[a-zA-Z0-9_]+)\s+(eq|ne)\s+(\'[^\']*\'|\"[^\"]*\")";
        $reg_ex .= "(@[a-zA-Z]+)?(\^{2}\S+:?\S+)?/i";
        preg_match_all($reg_ex, $filterStr, $eqExprs);
        foreach ($eqExprs[0] as $i => $eqExpr) {
            $parsedFilter['strEqExprs'][$i]['var'] = $this->_isDefined($eqExprs[1][$i]);
            $parsedFilter['strEqExprs'][$i]['operator'] = strtolower($eqExprs[2][$i]);
            $parsedFilter['strEqExprs'][$i]['value'] = trim($eqExprs[3][$i],"'\"");
            $parsedFilter['strEqExprs'][$i]['value_type'] = 'Literal';
            $parsedFilter['strEqExprs'][$i]['value_lang'] = substr($eqExprs[4][$i], 1);
            $dtype = substr($eqExprs[5][$i], 2);
            if ($dtype) {
                $parsedFilter['strEqExprs'][$i]['value_dtype'] = $this->_validateUri($dtype, RDF_RDQL_ERROR_AND);
                if ($dtype{0} != '<') {
                    $parsedFilter['strEqExprs'][$i]['value_dtype_is_qname'] = true;
                }
            } else {
                $parsedFilter['strEqExprs'][$i]['value_dtype'] = '';
            }

            $filterStr = str_replace($eqExprs[0][$i], " ##strEqExpr_$i## ", $filterStr);
        }
        // parse ?var [eq | ne] ?var
        $ii = count($parsedFilter['strEqExprs']);
        $reg_ex  = "/(\?[a-zA-Z0-9_]+)\s+(eq|ne)\s+(\?[a-zA-Z0-9_]+)/i";
        preg_match_all($reg_ex, $filterStr, $eqExprs);
        foreach ($eqExprs[0] as $i => $eqExpr) {
            $parsedFilter['strEqExprs'][$ii]['var'] = $this->_isDefined($eqExprs[1][$i]);
            $parsedFilter['strEqExprs'][$ii]['operator'] = strtolower($eqExprs[2][$i]);
            $parsedFilter['strEqExprs'][$ii]['value'] = $this->_isDefined($eqExprs[3][$i]);
            $parsedFilter['strEqExprs'][$ii]['value_type'] = 'variable';

            $filterStr = str_replace($eqExprs[0][$i], " ##strEqExpr_$ii## ", $filterStr);
            $ii++;
        }
        // parse ?var [eq | ne] <URI> or ?var [eq | ne] prefix:local_name
        $reg_ex  = "/(\?[a-zA-Z0-9_]+)\s+(eq|ne)\s+((<\S+>)|(\S+:\S*))/i";
        preg_match_all($reg_ex, $filterStr, $eqExprs);
        foreach ($eqExprs[0] as $i => $eqExpr) {
            $parsedFilter['strEqExprs'][$ii]['var'] = $this->_isDefined($eqExprs[1][$i]);
            $parsedFilter['strEqExprs'][$ii]['operator'] = strtolower($eqExprs[2][$i]);
            if ($eqExprs[4][$i]) {
                $parsedFilter['strEqExprs'][$ii]['value'] = trim($eqExprs[4][$i], "<>");
                $parsedFilter['strEqExprs'][$ii]['value_type'] = 'URI';
            } else if($eqExprs[5][$i]) {
                $this->_validateQName($eqExprs[5][$i], RDF_RDQL_ERROR_AND);
                $parsedFilter['strEqExprs'][$ii]['value'] = $eqExprs[5][$i];
                $parsedFilter['strEqExprs'][$ii]['value_type'] = 'QName';
            }

            $filterStr = str_replace($eqExprs[0][$i], " ##strEqExpr_$ii## ", $filterStr);
            $ii++;
        }
        $parsedFilter['evalFilterStr'] = $filterStr;

        // all that is left are numerical expressions and place holders for the above expressions
        preg_match_all("/\?[a-zA-Z0-9_]+/", $filterStr, $vars);
        foreach ($vars[0] as $var) {
            $parsedFilter['numExprVars'][] = $this->_isDefined($var);
        }

        return $parsedFilter;
    }

    /**
     * Find all query variables used in the WHERE clause.
     *
     * @return array [] = ?VARNAME
     * @access private
     */
    function findAllQueryVariables()
    {
        $vars = array();
        foreach ($this->parsedQuery['patterns'] as $pattern) {
            $count = 0;
            foreach ($pattern as $v) {
               if ($v['value'] && $v['value']{0} == '?') {
                    ++$count;
                    if (!in_array($v['value'], $vars)) {
                        $vars[] = $v['value'];
                    }
                }
            }
            if (!$count) {
                $errmsg = 'pattern contains no variables';
                return RDF_RDQL::raiseError(RDF_RDQL_ERROR_WHERE, null, null, $errmsg);
            }
        }

        return $vars;
    }

    /**
     * Replace all namespace prefixes in the pattern and constraint clause of an RDQL query
     * with the namespaces declared in the USING clause and default namespaces.
     *
     * @access private
     */
    function replaceNamespacePrefixes()
    {
        if (!isset($this->parsedQuery['ns'])) {
            $this->parsedQuery['ns'] = array();
        }
        // add default namespaces
        // if in an RDQL query a reserved prefix (e.g. rdf: rdfs:) is used
        // it will be overridden by the default namespace defined in constants.php
        $this->parsedQuery['ns'] = array_merge($this->parsedQuery['ns'], $GLOBALS['_RDF_RDQL_default_prefixes']);

        // replace namespace prefixes in the FROM clause
        if (isset($this->parsedQuery['sources'])) {
            foreach ($this->parsedQuery['sources'] as $n => $source) {
                if (isset($source['is_qname'])) {
                    $this->parsedQuery['sources'][$n] = $this->_replaceNamespacePrefix($source['value'], RDF_RDQL_ERROR_SOURCE);
                } else {
                    foreach ($this->parsedQuery['ns'] as $prefix => $uri) {
                        $source['value'] = eregi_replace("$prefix:", $uri, $source['value']);
                    }
                   $this->parsedQuery['sources'][$n] = $source['value'];
                }
            }
        }

        // replace namespace prefixes in the where clause
        foreach ($this->parsedQuery['patterns'] as $n => $pattern) {
            foreach ($pattern as $key => $v) {
                if ($v['value'] && $v['value']{0} != '?') {
                    if (isset($v['is_qname'])) {
                        $this->parsedQuery['patterns'][$n][$key]['value']
                            = $this->_replaceNamespacePrefix($v['value'], RDF_RDQL_ERROR_WHERE);
                        unset($this->parsedQuery['patterns'][$n][$key]['is_qname']);
                    } else { // is quoted URI (== <URI>) or Literal
                        if (isset($this->parsedQuery['patterns'][$n][$key]['is_literal'])) {
                            if (isset($this->parsedQuery['patterns'][$n][$key]['l_dtype_is_qname'])) {
                                $this->parsedQuery['patterns'][$n][$key]['l_dtype']
                                    = $this->_replaceNamespacePrefix($v['l_dtype'], RDF_RDQL_ERROR_WHERE);
                                unset($this->parsedQuery['patterns'][$n][$key]['l_dtype_is_qname']);	
                            } else {
                                foreach ($this->parsedQuery['ns'] as $prefix => $uri) {
                                    $this->parsedQuery['patterns'][$n][$key]['l_dtype']
                                        = eregi_replace("$prefix:", $uri, $this->parsedQuery['patterns'][$n][$key]['l_dtype']);
                                }
                            }
                        } else {
                            foreach ($this->parsedQuery['ns'] as $prefix => $uri) {
                                $this->parsedQuery['patterns'][$n][$key]['value']
                                    = eregi_replace("$prefix:", $uri, $this->parsedQuery['patterns'][$n][$key]['value']);
                            }
                        }
                    }
                }
            }
        }

        // replace prefixes in the constraint clause
        if (isset($this->parsedQuery['filters'])) {
            foreach ($this->parsedQuery['filters'] as $n => $filter) {
                foreach ($filter['strEqExprs'] as $i => $expr) {
                    if ($expr['value_type'] == 'QName') {
                        $this->parsedQuery['filters'][$n]['strEqExprs'][$i]['value']
                            = $this->_replaceNamespacePrefix($expr['value'], RDF_RDQL_ERROR_AND);
                        $this->parsedQuery['filters'][$n]['strEqExprs'][$i]['value_type'] = 'URI';
                    }
                    if ($expr['value_type'] == 'URI') {
                        foreach ($this->parsedQuery['ns'] as $prefix => $uri) {
                            $this->parsedQuery['filters'][$n]['strEqExprs'][$i]['value']
                                = eregi_replace("$prefix:", $uri, $this->parsedQuery['filters'][$n]['strEqExprs'][$i]['value']);
                        }
                    } elseif ($expr['value_type'] == 'Literal') {
                        if (isset($expr['value_dtype_is_qname'])) {
                            $this->parsedQuery['filters'][$n]['strEqExprs'][$i]['value_dtype']
                                = $this->_replaceNamespacePrefix($expr['value_dtype'], RDF_RDQL_ERROR_AND);
                            unset($this->parsedQuery['filters'][$n]['strEqExprs'][$i]['value_dtype_is_qname']);
                        } else {
                            foreach ($this->parsedQuery['ns'] as $prefix => $uri) {
                                $this->parsedQuery['filters'][$n]['strEqExprs'][$i]['value_dtype']
                                    = eregi_replace("$prefix:", $uri, $this->parsedQuery['filters'][$n]['strEqExprs'][$i]['value_dtype']);
                            }
                        }
                    }
                }
            }
        }

        unset($this->parsedQuery['ns']);
    }

    // =============================================================================
    // *************************** helper functions ********************************
    // =============================================================================
    /**
     * Remove whitespace-tokens from the array $this->tokens
     *
     * @access private
     */
    function _clearWhiteSpaces()
    {
        while (current($this->tokens) == ' '
            || current($this->tokens) == "\n"
            || current($this->tokens) == "\t"
            || current($this->tokens) == "\r"
        ) {
            unset($this->tokens[key($this->tokens)]);
        }
    }

    /**
     * Check if the query string of the given clause contains an undesired ','.
     * If a comma was correctly placed then remove it and clear all whitespaces.
     *
     * @param string $commaExpected
     * @param string $clause_error
     * @throws PHPError
     * @access private
     */
    function _checkComma($commaExpected, $clause_error)
    {
        $this->_clearWhiteSpaces();
        if (current($this->tokens) == ',') {
            if (!$commaExpected) {
                $errmsg = 'unexpected comma';
                return RDF_RDQL::raiseError($clause_error, null, null, $errmsg);
            } else {
                unset($this->tokens[key($this->tokens)]);
                $this->_checkComma(false, $clause_error);
            }
        }
    }

    /**
     * Check if the given token is either a variable (?var) or the first token of an URI (<URI>).
     * In case of an URI this function returns the whole URI string.
     *
     * @param string $token
     * @return array ['value'] = string
     * @throws PHPError
     * @access private
     */
    function _validateVarUri($token)
    {
        if ($token{0} == '?') {
            $token_res['value'] = $this->_validateVar($token, RDF_RDQL_ERROR_WHERE);
        } else {
            $token_res['value'] = $this->_validateUri($token, RDF_RDQL_ERROR_WHERE);
            if ($token{0} != '<') {
                $token_res['is_qname'] = true;
            }
        }
        return $token_res;
    }

    /**
     * Check if the given token is either a variable (?var) or the first token
     * of either an URI (<URI>) or a literal ("Literal").
     * In case of a literal return an array with literal properties (value, language, datatype).
     * In case of a variable or an URI return only ['value'] = string.
     *
     * @param string $token
     * @return array ['value'] = string
     *                 ['is_qname'] = boolean
     *                 ['is_literal'] = boolean
     *                 ['l_lang'] = string
     *                 ['l_dtype'] = string
     * @throws PHPError
     * @access private
     */
    function _validateVarUriLiteral($token)
    {
        if ($token{0} == '?') {
            $statement_object['value'] = $this->_validateVar($token, RDF_RDQL_ERROR_WHERE);
        } elseif ($token{0} == "'" || $token{0} == '"') {
            $statement_object = $this->_validateLiteral($token);
        } elseif ($token{0} == '<') {
            $statement_object['value'] = $this->_validateUri($token, RDF_RDQL_ERROR_WHERE);
        } elseif (ereg(':', $token)) {
            $statement_object['value'] = $this->_validateUri($token, RDF_RDQL_ERROR_WHERE);
            $statement_object['is_qname'] = TRUE;
        } else {
            $errmsg = " '$token' - ?Variable, &lt;URI&gt;, QName, or \"LITERAL\" expected";
            return RDF_RDQL::raiseError(RDF_RDQL_ERROR_WHR, null, null, $errmsg);
        }
        return $statement_object;
    }

    /**
     * Check if the given token is a valid variable name (?var).
     *
     * @param string $token
     * @param string $clause
     * @return string
     * @throws PHPError
     * @access private
     */
    function _validateVar($token, $clause_error)
    {
        $match = array();
        preg_match("/\?[a-zA-Z0-9_]+/", $token, $match);
        if (!isset($match[0]) || $match[0] != $token) {
            $errmsg = htmlspecialchars($token) . "' - variable name contains illegal characters";
            return RDF_RDQL::raiseError($clause_error, null, null, $errmsg);
        }
        unset($this->tokens[key($this->tokens)]);
        return $token;
    }

    /**
     * Check if $token is the first token of a valid URI (<URI>) and return the whole URI string
     *
     * @param string $token
     * @param string $clause_error
     * @return string
     * @throws PHPError
     * @access private
     */
    function _validateUri($token, $clause_error)
    {
        if ($token{0} != '<') {
            if (strpos($token, ':') && $this->_validateQName($token, $clause_error)) {
                unset($this->tokens[key($this->tokens)]);
                return rtrim($token, ':');
            }
            if ($clause_error == RDF_RDQL_ERROR_WHERE) {
                $errmsg = htmlspecialchars($token)
                    . "' - ?Variable or &lt;URI&gt; or QName expected";
            } else {
                $errmsg = htmlspecialchars($token)
                    . "' - &lt;URI&gt; or QName expected";
            }
            return RDF_RDQL::raiseError($clause_error, null, null, $errmsg);
        } else {
            $token_res = $token;
            while ($token{strlen($token)-1} != '>' && $token != null) {
                if ($token == '(' || $token == ')' || $token == ','
                    || $token == ' ' || $token == "\n" || $token == "\r"
                ) {
                    $errmsg = htmlspecialchars($token_res)
                        . "' - illegal input: '$token' - '>' missing";
                    return RDF_RDQL::raiseError($clause_error, null, null, $errmsg);
                }
                unset($this->tokens[key($this->tokens)]);
                $token = current($this->tokens);
                $token_res .= $token;
            }
            if ($token == null) {
                $errmsg = htmlspecialchars($token_res)
                    . "' - '>' missing";
                return RDF_RDQL::raiseError($clause_error, null, null, $errmsg);
            }
            unset($this->tokens[key($this->tokens)]);
            return trim($token_res, '<>');
        }
    }

    /**
     * Check if $token is the first token of a valid literal ("LITERAL") and
     * return an array with literal properties (value, language, datatype).
     *
     * @param string $token
     * @return array ['value'] = string
     *                   ['is_literal'] = boolean
     *                   ['l_lang'] = string
     *                   ['l_dtype'] = string
     *                   ['l_dtype_is_qname'] = boolean
     * @throws PHPError
     * @access private
     */
    function _validateLiteral($token)
    {
        $quotation_mark = $token{0};
        $statement_object = array (
            'value' => '',
            'is_literal' => true,
            'l_lang' => '',
            'l_dtype' => ''
        );
        $this->tokens[key($this->tokens)] = substr($token, 1);

        $return = false;
        foreach ($this->tokens as $k => $token) {
            if ($token != null && $token{strlen($token)-1} == $quotation_mark) {
                $token = rtrim($token, $quotation_mark);
                $return = true;
                // parse @language (^^datatype)?
            } elseif (strpos($token, $quotation_mark . '@')
                || substr($token, 0, 2) == $quotation_mark . '@'
            ) {
                $lang = substr($token, strpos($token, $quotation_mark . '@') + 2);
                if (strpos($lang, '^^') || substr($lang, 0, 2) == '^^') {
                    $dtype = substr($lang, strpos($lang, '^^') + 2);
                    if (!$dtype) {
                        $errmsg = $quotation_mark . $statement_object['value']
                            . $token . " - datatype expected";
                        return RDF_RDQL::raiseError(RDF_RDQL_ERROR_WHERE, null, null, $errmsg);
                    }
                    $statement_object['l_dtype'] = $this->_validateUri($dtype, RDF_RDQL_ERROR_WHERE);
                    if ($dtype{0} != '<') {
                        $statement_object['l_dtype_is_qname'] = true;
                    }
                    $lang = substr($lang, 0, strpos($lang, '^^'));
                }
                if (!$lang) {
                    $errmsg = $quotation_mark . $statement_object['value']
                        . $token . " - language expected";
                    return RDF_RDQL::raiseError(RDF_RDQL_ERROR_WHERE, null, null, $errmsg);
                }
                $statement_object['l_lang'] = $lang;
                $token = substr($token, 0, strpos($token, $quotation_mark . '@'));
                $return = true;
                // parse ^^datatype
            } elseif (strpos($token, $quotation_mark . '^^') || substr($token, 0, 3) == $quotation_mark . '^^') {
                $dtype = substr($token, strpos($token, $quotation_mark . '^^') + 3);
                if (!$dtype) {
                    $errmsg = $quotation_mark . $statement_object['value']
                        . $token . " - datatype expected";
                    return RDF_RDQL::raiseError(RDF_RDQL_ERROR_WHERE, null, null, $errmsg);
                }
                $statement_object['l_dtype'] = $this->_validateUri($dtype, RDF_RDQL_ERROR_WHERE);
                if ($dtype{0} != '<') {
                    $statement_object['l_dtype_is_qname'] = true;
                }
                $token = substr($token, 0, strpos($token, $quotation_mark . '^^'));
                $return = true;
            } elseif (strpos($token, $quotation_mark)) {
                $errmsg = "'$token' - illegal input";
                return RDF_RDQL::raiseError(RDF_RDQL_ERROR_WHERE, null, null, $errmsg);
            }
            $statement_object['value'] .= $token;
            unset($this->tokens[$k]);
            if ($return) {
                return $statement_object;
            }
        }
        $errmsg = "quotation end mark: $quotation_mark missing";
        return RDF_RDQL::raiseError(RDF_RDQL_ERROR_WHERE, null, null, $errmsg);
    }

    /**
     * Check if the given token is a valid QName. 
     *
     * @param   string  $token
     * @param   string  $clause_error
     * @return  boolean
     * @throws  PHPError
     * @access	private
     */
    function _validateQName($token, $clause_error)
    {
        $parts = explode(':', $token);
        if (count($parts) > 2) {
            $errmsg = "illegal QName: '$token'";
            return RDF_RDQL::raiseError($clause_error, null, null, $errmsg);
        }
        if (!$this->_validateNCName($parts[0])) {
            $errmsg = "illegal prefix in QName: '$token'";
            return RDF_RDQL::raiseError($clause_error, null, null, $errmsg);
        }
        if ($parts[1] && !$this->_validateNCName($parts[1])) {
            $errmsg = "illegal local part in QName: '$token'";
            return RDF_RDQL::raiseError($clause_error, null, null, $errmsg);
        }

        return true;
    }

    /**
     * Check if the given token is a valid NCName. 
     *
     * @param   string  $token
     * @return  boolean
     * @access	private
     */ 
    function _validateNCName($token)
    {
        preg_match("/[a-zA-Z_]+[a-zA-Z_0-9.\-]*/", $token, $match);
        if (isset($match[0]) && $match[0] == $token) {
            return true;
        }
        return false;
    }

    /**
     * Check if the given token is a valid namespace prefix.
     *
     * @param string $token
     * @return string
     * @throws PHPError
     * @access private
     */
    function _validatePrefix($token)
    {
        if (!$this->_validateNCName($token)) {
            $errmsg = "'" . htmlspecialchars($token)
                . "' - illegal input, namespace prefix expected";
            return RDF_RDQL::raiseError(RDF_RDQL_ERROR_USING, null, null, $errmsg);
        }
        unset($this->tokens[key($this->tokens)]);
        return $token;
    }

    /**
     * Replace a prefix in a given QName and return a full URI.
     *
     * @param   string  $qName
     * @param   string  $clasue_error
     * @return  string
     * @throws  PHPError
     * @access	private
     */ 
     function _replaceNamespacePrefix($qName, $clause_error)
     {
        $qName_parts = explode(':', $qName);
        if (!array_key_exists($qName_parts[0], $this->parsedQuery['ns'])) {
            $errmsg = "undefined prefix: '" .$qName_parts[0] . "' in: '$qName'";
            return RDF_RDQL::raiseError($clause_error, null, null, $errmsg);
        }
        return $this->parsedQuery['ns'][$qName_parts[0]] .$qName_parts[1];
     }

    /**
     * Check if all variables from the SELECT clause are defined in the WHERE clause
     *
     * @access private
     */
    function _checkSelectVars()
    {
        foreach ($this->parsedQuery['selectVars'] as $var) {
            $this->_isDefined($var);
        }
    }

    /**
     * Check if the given variable is defined in the WHERE clause.
     *
     * @param  $var string
     * @return string
     * @throws PHPError
     * @access private
     */
    function _isDefined($var)
    {
        $allQueryVars = $this->findAllQueryVariables();

        if (!in_array($var, $allQueryVars)) {
            $errmsg = "'$var' - variable must be defined in the WHERE clause";
            return RDF_RDQL::raiseError(RDF_RDQL_ERROR, null, null, $errmsg);
        }
        return $var;
    }

    /**
     * Throw an error if the regular expression from the AND clause is not quoted.
     *
     * @param string $filterString
     * @param string $lQuotMark
     * @param string $rQuotMark
     * @throws PHPError
     * @access private
     */
    function _checkRegExQuotation($filterString, $lQuotMark, $rQuotMark)
    {
        if (!$lQuotMark) {
            $errmsg = "'$filterString' - regular expressions must be quoted";
            return RDF_RDQL::raiseError(RDF_RDQL_ERROR_AND, null, null, $errmsg);
        }

        if ($lQuotMark != $rQuotMark) {
            $errmsg = "'$filterString' - quotation end mark in the regular expression missing";
            return RDF_RDQL::raiseError(RDF_RDQL_ERROR_AND, null, null, $errmsg);
        }
    }
} // end: Class RDQLParser

?>