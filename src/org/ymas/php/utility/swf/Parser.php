<?php
/**
 *  Swf utility class to parse the header of a swf file.
 *
 *  Please visit {@link http://www.adobe.com/devnet/swf.html} to get the latest
 *  swf file format specification.
 *
 *  A file name is to be provided and the instance will make available the data
 *  in the swf file header.
 *
 *  Many thanks to:
 *  @link http://www.zend.com//code/codex.php?ozid=1382&single=1
 *  @link http://www.fecj.org/extra/swfheader.class.php.txt
 *
 *  @author Yousif Masoud
 *  @version 0.0.1 (pre-alpha)
 */

namespace org\ymas\php\utility\swf;

class Parser
{
    /**
     *  Flag to display debug data
     *  @var bool
     */
    protected $_debug;

    /**
     *  String holding the Signature of the SWF file
     *  Can either be: "FWS" or "CWS"
     *  @var string
     */
    protected $_signature;

    /**
     *  @var int
     */
    protected $_version;

    /**
     *  Length of uncompressed file
     *  @var int (32 bit)
     */
    protected $_fileLength;

    protected $_xmin;
    protected $_xmax;
    protected $_ymin;
    protected $_ymax;

    /**
     *  Frame width in logical pixels
     *  @var int
     */
    protected $_frameWidth;

    /**
     *  Frame height in logical pixels
     *  @var int
     */
    protected $_frameHeight;

    /**
     *  @var float
     */
    protected $_frameRate;

    /**
     *  @var int
     */
    protected $_frameCount;

    public function __construct($fileName, $dbg = false)
    {
        if (null === $fileName) {
            throw new Exception("Please provide a valid swf file.");
        }

        $this->setFileName($fileName);

        $this->_debug = $dbg;
        $this->extractData();
    }

    public function setFileName($fn)
    {
        if (file_exists($fn) && is_readable($fn)) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $fn);
            finfo_close($finfo);
        }

        if ($mime === 'application/x-shockwave-flash') {
            $this->_fileName = $fn;
        }
    }

    public function getFileName()
    {
        return $this->_fileName;
    }

    public function __get($name)
    {
        $var = '_' . $name;
        return $this->$var;
    }

    public function extractData()
    {
        $file = $this->getFileName();
        $fh = fopen($file, 'rb');
        $this->_signature = fread($fh, 3);
        $this->_version = fread($fh, 1);

        // file Size
        $size = unpack('Vsize',fread($fh, 4));
        $this->_fileLength = $size['size'];
        // end of uncompressed data ----------------------------------------------------

        $buffer = fread($fh, $size['size']);
        if ($this->_signature === 'CWS') {
            $buffer = gzuncompress($buffer, $size['size']);
        }

        // need to get the first 5 bits of the RECT structure.
        $b = ord(substr($buffer, 0, 1));
        // knock the byte off the buffer
        $buffer = substr($buffer, 1);
        // current byte (cbyte)
        $cbyte = $b;
        // number of bits use to hold Xmin, Xmax
        // Ymin, Ymax.
        $bits = $b>>3;
        // current value (cval)
        $cval = "";
        // leaving the last three bits of the current byte
        // as the first 5 bits were used to store the size
        // nbits
        $cbyte &= 7;

        // move the last 3 bits to the beginning of the
        // current byte.  These last three bits are the ones left
        // after the first 5 bits are read.
        $cbyte <<= 5;

        $cbit = 2;
        // Need to get Xmin, Xmax, Ymin, Ymax from RECT
        // vals		|	variable
        // 0		|	Xmin
        // 1		| 	Xmax
        // 2		|	Ymin
        // 3		| 	Ymax
        for ($vals = 0; $vals < 4; $vals++) {
            $bitcount = 0;
            while ($bitcount<$bits) {
                if ($cbyte&128) {
                    $cval .= "1";
                } else {
                    $cval .= "0";
                }

                $cbyte <<= 1;
                $cbyte &= 255;
                $cbit--;
                $bitcount++;

                if ($cbit < 0) {
                    $cbyte = ord(substr($buffer, 0, 1));
                    $buffer = substr($buffer, 1);
                    $cbit = 7;
                }
            }

            // now we have the 15 bits used to store a
            // coordinate, time to calculate.
            $c = 1; 	//	2^0, c holds the current place value in the binary system.
            $val = 0; 	//	current value

            //	need to get the decimal value of $cval via SUM(2^n*$bitValue)
            //	need to reverse defaul place values from 2^n, 2^(n-1), 2^(n-2) ... 2^2, 2^1, 2^0=1
            $tmpVal = strrev($cval);

            for ($n=0; $n<strlen($tmpVal); $n++) {
                $bitVal = substr($tmpVal, $n, 1);
                if ((int) $bitVal === 1) {
                    $val += $c;
                }
                // set $c to the next place value
                $c *= 2;
            }

            //	Convert from Twips to Pixels
            //	1 twip = 1/20 logical pixel
            $val /= 20;

            switch ($vals) {
                case 0:
                    $this->_xmin = $val;
                    break;
                case 1:
                    $this->_xmax = $val;
                    $this->_frameWidth = $this->_xmax - $this->_xmin;
                    break;
                case 2:
                    $this->_ymin = $val;
                    break;
                case 3:
                    $this->_ymax = $val;
                    $this->_frameHeight = $this->_ymax - $this->_ymin;
                    break;
            }
            $cval = "";
        }

        //	Frame rate
        //	Frame rate is stored as a float stored in 16-bits
        //	8 bits for number, 8 bits for fraction
        $fps = array();
        for ($i = 0; $i<2; $i++) {
            $t = ord(substr($buffer,0,1));
            $buffer = substr($buffer, 1);
            $fps[] = $t;
        }
        $this->_frameRate = implode('.', $fps);

        //	Number of frames
        $frames = 0;
        $t = unpack('vcount', (substr($buffer, 0, 2)));
        $this->_frameCount = $t['count'];
        $buffer = substr($buffer, 2);
        fclose($fh);
    }
}
