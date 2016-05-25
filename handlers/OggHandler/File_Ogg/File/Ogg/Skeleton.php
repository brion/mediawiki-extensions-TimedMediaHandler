<?php
/* vim: set expandtab tabstop=4 shiftwidth=4: */
// +----------------------------------------------------------------------------+
// | File_Ogg PEAR Package for Accessing Ogg Skeleton Bitstreams                |
// | Copyright (c) 2016                                                         |
// | Brion Vibber <bvibber@wikimedia.org>                                       |
// +----------------------------------------------------------------------------+
// | This library is free software; you can redistribute it and/or              |
// | modify it under the terms of the GNU Lesser General Public                 |
// | License as published by the Free Software Foundation; either               |
// | version 2.1 of the License, or (at your option) any later version.         |
// |                                                                            |
// | This library is distributed in the hope that it will be useful,            |
// | but WITHOUT ANY WARRANTY; without even the implied warranty of             |
// | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU          |
// | Lesser General Public License for more details.                            |
// |                                                                            |
// | You should have received a copy of the GNU Lesser General Public           |
// | License along with this library; if not, write to the Free Software        |
// | Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA |
// +----------------------------------------------------------------------------+


/**
 * Logical bitstream access class for skeleton tracks, which contain
 * metadata about the file. 
 *
 * @author      Brion Vibber <bvibber@wikimedia.org>
 * @category    File
 * @copyright   Brion Vibber <bvibber@wikimedia.org>
 * @license     http://www.gnu.org/copyleft/lesser.html GNU LGPL
 * @link        http://pear.php.net/package/File_Ogg
 * @link        http://www.opus-codec.org/
 * @package     File_Ogg
 * @version     1
 */
class File_Ogg_Skeleton extends File_Ogg_Bitstream
{
    var $valid = false;
    var $fishead = [];
    var $fisbones = [];
    var $indexes = [];

    /**
     * @access  private
     */
    function __construct($streamSerial, $streamData, $filePointer)
    {
        parent::__construct($streamSerial, $streamData, $filePointer);
        $this->valid = $this->_readSkeleton();
    }

    function _readSkeleton()
    {
        $npages = count($this->_streamData['pages']);
        if ($npages < 1) {
            echo "NOTHING\n";
            return false;
        }
        for ($i = 0; $i < $npages; $i++) {
            $this->_seekPage($i);
            $magic = $this->_readBytes(8);
            $this->_seekPage($i);

            if ($magic === "fishead\x00" && $i === 0) {
                $fishead = $this->_readFisHead($i);
                if ($fishead) {
                    $this->fishead = $fishead;
                } else {
                    echo "FAIL head\n";
                    return false;
                }
            } else if ($magic === "fisbone\x00" && $i > 0) {
                $fisbone = $this->_readFisBone($i);
                if ($fisbone) {
                    $this->fisbones[] = $fisbone; 
                } else {
                    echo "FAIL bone\n";
                    return false;
                }
            } else if (substr($magic, 0, 6) === "index\x00" && $i > 0) {
                $index = $this->_readIndex($i);
                if ($index) {
                    $this->indexes[] = $index;
                } else {
                    echo "FAIL index\n";
                    return false;
                }
            } else {
                echo "FAIL\n";
                return false;
            }
        }
        return true;
    }

    function _seekPage($pageIndex)
    {
        fseek($this->_filePointer, $this->_streamData['pages'][$pageIndex]['body_offset'], SEEK_SET);
    }

    function _readBytes($count)
    {
        return fread($this->_filePointer, $count);
    }

    function _readUint16()
    {
        $data = $this->_readBytes(2);
        return ord($data{0}) | ord($data{1}) << 8;
    }

    function _readUint32()
    {
        $low = $this->_readUint16();
        $high = $this->_readUint16();
        return $low | $high << 16;
    }

    function _readInt64()
    {
        $low = $this->_readUint32();
        $high = $this->_readUint32();
        return $low | $high << 32;
    }

    function _readVariableInt()
    {
        $val = 0;
        $shift = 0;
        do {
            $data = $this->_readBytes(1);
            if ($data === false) {
                return false;
            }
            $byte = ord($data);
            $val = ($byte & 0x7f) << $shift;
            $shift += 7;
        } while (($byte & 0x80) === 0);
        return $val;
    }

    function _readFisHead()
    {
        $magic = $this->_readBytes(8);
        if ($magic !== "fishead\x00") {
            return false;
        }

        $fishead = array();
        $version_major = $this->_readUint16();
        $version_minor = $this->_readUint16();
        $fishead['version'] = "$version_major.$version_minor"; 

        // check the packet size?
        $fishead['ptime_num'] = $this->_readInt64();
        $fishead['ptime_denom'] = $this->_readInt64();
        $fishead['btime_num'] = $this->_readInt64();
        $fishead['btime_denom'] = $this->_readInt64();
        $fishead['utc_time'] = $this->_readBytes(20);

        if (version_compare($fishead['version'], '3.2', 'ge')) {
            $fishead['segment_length'] = $this->_readInt64();
            $fishead['content_offset'] = $this->_readInt64();
        }

        return $fishead;
    }

    function _readFisBone()
    {
        $magic = $this->_readBytes(8);
        if ($magic !== "fisbone\x00") {
            return false;
        }

        $fisbone = array();
        $fisbone['message_header_offset'] = $this->_readUint32();
        $fisbone['serial'] = $this->_readUint32();
        $fisbone['header_count'] = $this->_readUint32();
        $fisbone['granule_rate_num'] = $this->_readInt64();
        $fisbone['granule_rate_denom'] = $this->_readInt64();
        $fisbone['base_granule'] = $this->_readInt64();
        $fisbone['preroll'] = $this->_readUint32();
        $fisbone['granulepos'] = $this->_readUint32() & 0x1f;

        // message headers....?
        return $fisbone;
    }

    function _readIndex()
    {
        $magic = $this->_readBytes(6);
        if ($magic !== "index\x00") {
            return false;
        }

        $index = array();
        $index['serial'] = $this->_readUint32();
        $index['keypoint_count'] = $this->_readInt64();
        $index['time_denom'] = $this->_readInt64();
        $index['first_sample_num'] = $this->_readInt64();
        $index['last_sample_num'] = $this->_readInt64();
        $index['offset_deltas'] = array();
        $index['ptime_num_deltas'] = array();

        for($i = 0; $i < $index['keypoint_count']; $i++) {
            $index['offset_deltas'][] = $this->_readVariableInt();
            $index['ptime_num_deltas'][] = $this->_readVariableInt();
        }
        return $index;
    }

    function getKeypointIndex($serial)
    {
        if (!$this->valid) {
            return false;
        }
        foreach($this->indexes as $index) {
            if ($index['serial'] == $serial) {
                return $this->_expandIndex($index);
            }
        }
        return false;
    }

    function _expandIndex($index)
    {
        $data = array();
        $time_denom = $index['time_denom'];
        for($i = 0; $i < $index['keypoint_count']; $i++) {
            $offset += $index['offset_deltas'][$i];
            $ptime_num += $index['ptime_num_deltas'][$i];
            $data[] = [
                'offset' => $offset,
                'ptime' => $ptime_num / $time_denom
            ];
        }
        return $data;
    }
}


?>