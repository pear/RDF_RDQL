<?php
// ----------------------------------------------------------------------------------
// Class: RDF_RDQL_ResultIterator
// ----------------------------------------------------------------------------------
/**
 * Iterator for traversing RDQL results.
 * This class can be used for iterating forward and backward trough RDQL results.
 * It should be instanced using the RDQLQueryAsIterator() method of a Model_Memory or a Model_MDB.
 *
 * @version V0.7
 * @author Daniel Westphal <mail@d-westphal.de>, Chris Bizer <chris@bizer.de>
 * @package RDQL
 * @access public
 */
class RDF_RDQL_ResultIterator extends RDF_Object
{
    /**
     * Reference to the RDQL result
     *
     * @var array RDQLResult
     * @access private
     */
    var $RDQLResult;

    /**
     * Current position
     * RDQLResultIterator does not use the build in PHP array iterator,
     * so you can use serveral iterators on a single RDQL result.
     *
     * @var integer
     * @access private
     */
    var $position;

    /**
     * @param object RDQLResult
     * @access public
     */
    function RDF_RDQL_ResultIterator(&$RDQLResult)
    {
        $noResult = true;
        foreach($RDQLResult[0] as $value) {
            if ($value != null) {
                $noResult = false;
                break;
            }
        }
        if ($noResult) {
            $this->RDQLResult = null;
        } else {
            $this->RDQLResult = $RDQLResult;
            $this->position = -1;
       }
    }

    /**
     * Returns the labels of the result as array.
     *
     * @return array of strings with the result labels OR null if there are no results.
     * @access public
     */
    function getResultLabels()
    {
        if (count($this->RDQLResult) > 0) {
            return array_keys($this->RDQLResult[0]);
        } else {
            return null;
        }
    }

    /**
     * Returns the number of results.
     *
     * @return integer
     * @access public
     */
    function countResults()
    {
        return count($this->RDQLResult);
    }

    /**
     * Returns TRUE if there are more results.
     *
     * @return boolean
     * @access public
     */
    function hasNext()
    {
        if ($this->position < count($this->RDQLResult) - 1) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Returns TRUE if the first result has not been reached.
     *
     * @return boolean
     * @access public
     */
    function hasPrevious()
    {
        if ($this->position > 0) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Returns the next result array.
     *
     * @param integer $element
     * @return result array OR single result if $element was specified OR null if there is no next result.
     * @access public
     */
    function next($element = null)
    {
        if ($this->position < count($this->RDQLResult) - 1) {
            $this->position++;
            if ($element) {
                return $this->RDQLResult[$this->position][$element];
            } else {
                return $this->RDQLResult[$this->position];
            }
        } else {
            return null;
        }
    }

    /**
     * Returns the previous result.
     *
     * @param integer $element
     * @return result array OR single result if $element was specified OR null if there is no next result.
     * @access public
     */
    function previous($element = null)
    {
        if ($this->position > 0) {
            $this->position--;
            if ($element) {
                return $this->RDQLResult[$this->position][$element];
            } else {
                return $this->RDQLResult[$this->position];
            }
        } else {
            return null;
        }
    }

    /**
     * Returns the current result.
     *
     * @param integer $element
     * @return result array OR single result if $element was specified OR null if there is no next result.
     * @access public
     */
    function current($element = null)
    {
        if (($this->position >= 0) && ($this->position < count($this->RDQLResult))) {
            if ($element) {
                return $this->RDQLResult[$this->position][$element];
            } else {
                return $this->RDQLResult[$this->position];
            }
        } else {
            return null;
        }
    }

    /**
     * Moves the pointer to the first result.
     *
     * @return void
     * @access public
     */
    function moveFirst()
    {
        $this->position = 0;
    }

    /**
     * Moves the pointer to the last result.
     *
     * @return void
     * @access public
     */
    function moveLast()
    {
        $this->position = count($this->RDQLResult) - 1;
    }

    /**
     * Moves the pointer to a specific result.
     * If you set an off-bounds value, next(), previous() and current() will return null
     *
     * @return void
     * @access public
     */
    function moveTo($position)
    {
        $this->position = $position;
    }

    /**
     * Returns the current position of the iterator.
     *
     * @return integer
     * @access public
     */
    function getCurrentPosition()
    {
        return $this->position;
    }
}

?>