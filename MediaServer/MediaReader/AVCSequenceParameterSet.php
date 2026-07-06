<?php


namespace MediaServer\MediaReader;

use MediaServer\Utils\BitReader;

/**
 * @purpose 视频序列帧参数解码
 * @author yanglong
 */
class AVCSequenceParameterSet extends BitReader
{
    /** 编码档次 */
    public $profile;
    /** 等级，根据不同场景选用，需要搭配编码档次使用 */
    public $level;
    /** 宽度 */
    public $width;
    /** 高度 */
    public $height;
    /** 参考帧数量：表示b帧或者p帧解码需要参考多少张之前的帧来解码 */
    public $avc_ref_frames = 0;

    public function __construct($data)
    {
        parent::__construct($data);
        /** 读取数据 */
        $this->readData();
    }

    /**
     * 获取编码档次
     */
    public function getAVCProfileName()
    {
        switch ($this->profile) {
            case 1:
                return 'Main';
            case 2:
                return 'Main 10';
            case 3:
                return 'Main Still Picture';
            case 66:
                return 'Baseline';
            case 77:
                return 'Main';
            case 100:
                return 'High';
            default:
                return '';
        }
    }

    public function readData()
    {
        /*$data = [];
        $data['version'] = ord($this->data[$this->currentBytes++]);
        $data['profile'] = ord($this->data[$this->currentBytes++]);
        $data['profileCompatibility'] = ord($this->data[$this->currentBytes++]);
        $data['level'] = ord($this->data[$this->currentBytes++]);
        $data['naluSize'] = (ord($this->data[$this->currentBytes++]) & 0x03) + 1;
        $data['nbSps'] = ord($this->data[$this->currentBytes++]) & 0x1F;

        $data['sps'] = [];
        for ($i = 0; $i < $data['nbSps']; $i++) {
            //读取sps
            $len = (ord($this->data[$this->currentBytes++]) << 8) | ord($this->data[$this->currentBytes++]);
           var_dump(bin2hex(substr($this->data, $this->currentBytes, $len)));
            //var_dump(base64_encode(substr($this->data, $this->currentBytes, $len)));
            $byteTmp=$this->currentBytes;
            var_dump(bin2hex($this->data[$this->currentBytes]));

            $nalType=ord($this->data[$this->currentBytes++]) & 0x1f;

            if($nalType !== 0x07){
                continue;
            }
            $sps=[];
            $sps['nalType']=$nalType;
            $sps['profileIdc']=ord($this->data[$this->currentBytes++]);
            $sps['flags']=ord($this->data[$this->currentBytes++]);
            $sps['levelIdc']=ord($this->data[$this->currentBytes++]);

            $data['sps'][] = $sps;
            $this->currentBytes = $byteTmp+$len;
        }

        $data['nbPps'] = ord($this->data[$this->currentBytes++]);
        $data['pps'] = [];
        for ($i = 0; $i < $data['nbPps']; $i++) {
            //读取sps
            $len = (ord($this->data[$this->currentBytes++]) << 8) | ord($this->data[$this->currentBytes++]);
            $data['pps'][] = substr($this->data, $this->currentBytes, $len);
            $this->currentBytes += $len;
        }

        var_dump($data);
        return;
        */

        //configurationVersion
        /** 跳过8个字节 */
        $this->skipBits(8);
        //profile
        /** 获取资源概要 */
        $this->profile = $profile = $this->getBits(8);                               // read profile
        //profile compat
        /** 跳过8个字节 */
        $this->skipBits(8);
        /** 获取画面等级信息 */
        $this->level = $level = $this->getBits(8);                         // level_idc
        /** 视频画面的h264格式的编码信息 */
        $naluSize = ($this->getBits(8) & 0x03) + 1;
        /** NAL 单元流参数 用户描述h264的视频编码信息 */
        $nb_sps = $this->getBits(8) & 0x1F;


        if ($nb_sps === 0) {
            echo "none sps", PHP_EOL;
            return;
        }

        /** 指针移动16个字节 */
        //nalSize
        $this->getBits(16);

        /* nal type */
        if (($this->getBits(8) & 0x1F) != 0x07) {
            return;
        }

        /** 读取sps 编码信息 */
        /* SPS */
        $profile_idc = $this->getBits(8);

        /** 获取视频标记 */
        /* flags */
        $this->getBits(8);

        /** 获取等级信息 */
        /* level idc */
        $this->getBits(8);

        /**  */
        $this->expGolombUe();                                   // seq_parameter_set_id // sps

        if ($profile_idc == 100 || $profile_idc == 110 ||
            $profile_idc == 122 || $profile_idc == 244 || $profile_idc == 44 ||
            $profile_idc == 83 || $profile_idc == 86 || $profile_idc == 118) {
            /* chroma format idc */
            /** 色度格式idc */
            $cf_idc = $this->expGolombUe();

            if ($cf_idc == 3) {

                /* separate color plane */
                /** 单独的彩色平面 */
                $this->getBits(1);
            }

            /** 处理亮度 比特深度亮度 */
            /* bit depth luma - 8 */
            $this->expGolombUe();

            /* bit depth chroma - 8 */
            /** 位深度色度 */
            $this->expGolombUe();

            /* qpprime y zero transform bypass */
            /** 变换旁路 */
            $this->getBits(1);

            /* seq scaling matrix present */
            /** 缩放比例矩阵 */
            if ($this->getBits(1)) {

                for ($n = 0; $n < ($cf_idc != 3 ? 8 : 12); $n++) {

                    /* seq scaling list present */
                    if ($this->getBits(1)) {

                        /** 比例列表 */
                        /* TODO: scaling_list()
                        if (n < 6) {
                        } else {
                        }
                        */
                    }
                }
            }
        }

        /** 获取最大帧数 */
        /* log2 max frame num */
        $this->expGolombUe();

        /* pic order cnt type */
        switch ($this->expGolombUe()) {
            case 0:

                /* max pic order cnt */
                $this->expGolombUe();
                break;

            case 1:

                /* delta pic order alwys zero */
                $this->getBits(1);

                /* offset for non-ref pic */
                $this->expGolombUe();

                /* offset for top to bottom field */
                $this->expGolombUe();

                /* num ref frames in pic order */
                $num_ref_frames = $this->expGolombUe();

                for ($n = 0; $n < $num_ref_frames; $n++) {

                    /* offset for ref frame */
                    $this->expGolombUe();
                }
        }


        /* num ref frames */
        $this->avc_ref_frames = $this->expGolombUe();

        /* gaps in frame num allowed */
        $this->getBits(1);

        /* pic width in mbs - 1 */
        $width = $this->expGolombUe();

        /* pic height in map units - 1 */
        $height = $this->expGolombUe();

        /* frame mbs only flag */
        $frame_mbs_only = $this->getBits(1);

        if (!$frame_mbs_only) {

            /* mbs adaprive frame field */
            $this->getBits(1);
        }

        /* direct 8x8 inference flag */
        $this->getBits(1);

        /* frame cropping */
        if ($this->getBits(1)) {

            $crop_left = $this->expGolombUe();
            $crop_right = $this->expGolombUe();
            $crop_top = $this->expGolombUe();
            $crop_bottom = $this->expGolombUe();

        } else {
            $crop_left = 0;
            $crop_right = 0;
            $crop_top = 0;
            $crop_bottom = 0;
        }

        $this->level = $this->level / 10.0;
        $this->width = ($width + 1) * 16 - ($crop_left + $crop_right) * 2;
        $this->height = (2 - $frame_mbs_only) * ($height + 1) * 16 - ($crop_top + $crop_bottom) * 2;

    }


}