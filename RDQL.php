<?php 

require_once 'RDF.php';

// ----------------------------------------------------------------------------------
// RDQL Error Messages
// ----------------------------------------------------------------------------------
define('RDF_RDQL_ERROR',        -1);
define('RDF_RDQL_ERROR_SYNTAX', -2);
define('RDF_RDQL_ERROR_SELECT', -3);
define('RDF_RDQL_ERROR_SOURCE', -4);
define('RDF_RDQL_ERROR_WHERE',  -5);
define('RDF_RDQL_ERROR_AND',    -6);
define('RDF_RDQL_ERROR_USING',  -7);
// ----------------------------------------------------------------------------------
// RDQL default namespace prefixes
// ----------------------------------------------------------------------------------
$GLOBALS['_RDF_RDQL_default_prefixes'] = array(
    'rdf'  => 'http://www.w3.org/1999/02/22-rdf-syntax-ns#',
    'rdfs' => 'http://www.w3.org/2000/01/rdf-schema#',
    'xsd'  => 'http://www.w3.org/2001/XMLSchema#'
);

class RDF_RDQL
{
    // }}}
    // {{{ raiseError()

    /**
     * This method is used to communicate an error and invoke error
     * callbacks etc.  Basically a wrapper for PEAR::raiseError
     * without the message string.
     *
     * @param mixed    integer error code, or a PEAR error object (all
     *                 other parameters are ignored if this parameter is
     *                 an object
     *
     * @param int      error mode, see PEAR_Error docs
     *
     * @param mixed    If error mode is PEAR_ERROR_TRIGGER, this is the
     *                 error level (E_USER_NOTICE etc).  If error mode is
     *                 PEAR_ERROR_CALLBACK, this is the callback function,
     *                 either as a function name, or as an array of an
     *                 object and method name.  For other error modes this
     *                 parameter is ignored.
     *
     * @param string   Extra debug information.  Defaults to the last
     *                 query and native error code.
     *
     * @return object  a PEAR error object
     *
     * @see PEAR_Error
     */
    function &raiseError($code = null, $mode = null, $options = null, $userinfo = null)
    {
        // The error is yet a MDB error object
        if (is_object($code)) {
            // because we the static PEAR::raiseError, our global
            // handler should be used if it is set
            if ($mode === null && !empty($this->_default_error_mode)) {
                $mode    = $this->_default_error_mode;
                $options = $this->_default_error_options;
            }
            return PEAR::raiseError($code, null, $mode, $options, null, null, true);
        }

        return PEAR::raiseError(null, $code, $mode, $options, $userinfo, 'RDF_RDQL_Error', true);
    }
    /**
     * Return a textual error message for a RAP error code.
     *
     * @access  public
     * @param   int     error code
     * @return  string  error message
     */
    function errorMessage($value)
    {
        // make the variable static so that it only has to do the defining on the first call
        static $errorMessages;

        // define the varies error messages
        if (!isset($errorMessages)) {
            $errorMessages = array(
                RDF_RDQL_ERROR              => 'Unknown error',
                RDF_RDQL_ERROR_SYNTAX       => 'Syntax error',
                RDF_RDQL_ERROR_SELECT       => 'Error in the SELECT clause',
                RDF_RDQL_ERROR_SOURCE       => 'Error in the SOURCE clause',
                RDF_RDQL_ERROR_WHERE        => 'Error in the WHERE clause',
                RDF_RDQL_ERROR_AND          => 'Error in the AND clause',
                RDF_RDQL_ERROR_USING        => 'Error in the USING clause',
            );
        }

        // If this is an error object, then grab the corresponding error code
        if (RDF_RDQL::isError($value)) {
            $value = $value->getCode();
        }

        // return the textual error message corresponding to the code
        return isset($errorMessages[$value]) ? $errorMessages[$value] : $errorMessages[RDF_RDQL_ERROR];
    } // end func errorMessage

    function isError($error)
    {
        return is_a($value, 'RDF_RDQL_Error');
    }

    /**
     * Perform an RDQL query on this Model_MDB.
     * This method returns an associative array of variable bindings.
     * The values of the query variables can either be RAP's objects (instances of Node)
     * if $returnNodes set to TRUE, or their string serialization.
     *
     * @access public
     * @param object model
     * @param string $queryString
     * @param boolean $returnNodes
     * @return array [][?VARNAME] = object Node  (if $returnNodes = TRUE)
     *       OR  array   [][?VARNAME] = string
     */
    function RDQLQuery(&$model, $queryString, $returnNodes = true)
    {
        if (!is_a($model, 'RDF_Model')) {
            $errmsg = 'Parameter is not an RDF_Model';
            return RDF::raiseError(RDF_RDQL_ERROR, null, null, $errmsg);
        }
        $parser =& new RDF_RDQL_Parser();
        $parsedQuery = &$parser->parseQuery($queryString);
        $model_class = get_class($model);
        // this method can only query this model
        // if another model was specified in the from clause throw an error
        if (strtolower($model_class) == 'rdf_model_mdb') {
            if (isset($parsedQuery['sources'][0])
                && $parsedQuery['sources'][0] != $model->modelURI
            ) {
                $errmsg = 'Method can only query this Model_MDB';
                return RDF_RDQL::raiseError(RDF_RDQL_ERROR, null, null, $errmsg);
            }
            $engine =& new RDF_RDQL_Engine_MDB();
        } elseif (strtolower($model_class) == 'rdf_model_memory') {
            if (isset($parsedQuery['sources'][1])) {
                $errmsg = 'Method can only query this Model_Memory';
                return RDF::raiseError(RDF_ERROR_MISMATCH, null, null, $errmsg);
            }
            $engine =& new RDF_RDQL_Engine_Memory();
        } else {
            $errmsg = 'Model type is not supported: ' . $model_class;
            return RDF::raiseError(RDF_RDQL_ERROR, null, null, $errmsg);
        }

        $res = &$engine->queryModel($model, $parsedQuery, $returnNodes);

        return $res;
    }


    /**
     * Perform an RDQL query on this Model_MDB.
     * This method returns an RDQLResultIterator of variable bindings.
     * The values of the query variables can either be RAP's objects (instances of Node)
     * if $returnNodes set to TRUE, or their string serialization.
     *
     * @access public
     * @param object model
     * @param string $queryString
     * @param boolean $returnNodes
     * @return object RDQLResultIterator = with values as object Node  (if $returnNodes = TRUE)
     *       OR  object RDQLResultIterator = with values as strings if (if $returnNodes = FALSE)
     */
    function RDQLQueryAsIterator(&$model, $queryString, $returnNodes = true)
    {
        if (!is_a($model, 'RDF_Model')) {
            $errmsg = 'Parameter is not an RDF_Model';
            return RDF::raiseError(RDF_RDQL_ERROR, null, null, $errmsg);
        }

        return new RDF_RDQL_ResultIterator(RDF_RDQL::RDQLQuery($model, $queryString, $returnNodes));
    }
}

/**
 * RDF_RDQL_Error implements a class for reporting RDF RDQL error
 * messages.
 *
 * @package RDF_RDQL
 * @category RDF
 * @author  Stig Bakken <ssb@fast.no>
 */
class RDF_RDQL_Error extends PEAR_Error
{
    // }}}
    // {{{ constructor

    /**
     * RDF_Error constructor.
     *
     * @param mixed   $code      RDF error code, or string with error message.
     * @param integer $mode      what 'error mode' to operate in
     * @param integer $level     what error level to use for
     *                           $mode & PEAR_ERROR_TRIGGER
     * @param smixed  $debuginfo additional debug info, such as the last query
     */
    function RDF_RDQL_Error($code = RDF_RDQL_ERROR, $mode = PEAR_ERROR_RETURN,
              $level = E_USER_NOTICE, $debuginfo = null)
    {
        if (is_int($code)) {
            $this->PEAR_Error('RDF RDQL Error: '.RDF_RDQL::errorMessage($code), $code,
                $mode, $level, $debuginfo);
        } else {
            $this->PEAR_Error("RDF RDQL Error: $code", RDF_RDQL_ERROR, $mode, $level,
                $debuginfo);
        }
    }
}

// Include RQQL classes
require_once 'RDF/RDQL/Parser.php';
require_once 'RDF/RDQL/Engine/MDB.php';
require_once 'RDF/RDQL/Engine/Memory.php';
require_once 'RDF/RDQL/ResultIterator.php';

?>