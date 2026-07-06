<?php


namespace MediaServer\MediaReader;

use MediaServer\Utils\BitReader;

/**
 * @purpose H264视频SPS序列参数集解析（AVC Decoder Configuration Record）
 * @author yanglong
 */
class AVCSequenceParameterSet extends BitReader
{
    /** 真实SPS内部profile_idc 66=Baseline 77=Main 100=High */
    public $profile;
    /** 原始level整型 31/41/42 */
    public $level_idc;
    /** 格式化等级字符串 3.1 / 4.1（新） */
    public $levelStr;
    /** 兼容旧业务读取的level字段 */
    public $level;
    /** 视频宽 */
    public $width;
    /** 视频高 */
    public $height;
    /** 最大参考帧数量 num_ref_frames */
    public $avc_ref_frames = 0;
    /** 解析得出帧率，无VUI时序为null */
    public $frameRate = null;
    /** 采样宽高比分子 */
    public $sarNum = 1;
    /** 采样宽高比分母 */
    public $sarDen = 1;
    /** SAR比值 */
    public $sarRatio = 1.0;

    public function __construct($data)
    {
        parent::__construct($data);
        $this->readData();
        // 调试打印输出
//        echo "profile_idc: {$this->profile} 名称: {$this->getAVCProfileName()}\n";
//        echo "level: {$this->level}\n";
//        echo "width: {$this->width} height: {$this->height}\n";
//        echo "avc_ref_frames: {$this->avc_ref_frames}\n";
//        echo "frameRate: " . var_export($this->frameRate, true) . "\n";
//        echo "sarNum:{$this->sarNum} sarDen:{$this->sarDen} ratio:{$this->sarRatio}\n\n";
    }

    /**
     * 根据profile_idc返回档次名称
     */
    public function getAVCProfileName()
    {
        switch ($this->profile) {
            case 66:
                return 'Baseline';
            case 77:
                return 'Main';
            case 100:
                return 'High';
            case 110:
                return 'High10';
            case 122:
                return 'High422';
            case 244:
                return 'High444 Predictive';
            case 44:
            case 83:
            case 86:
            case 118:
                return 'Extended';
            default:
                return 'Unknown';
        }
    }

    /**
     * 缩放矩阵解析
     */
    protected function scalingList($sizeOfScalingList)
    {
        $lastScale = 8;
        $nextScale = 0;
        for ($j = 0; $j < $sizeOfScalingList; $j++) {
            if ($nextScale !== 0) {
                $nextScale = ($this->expGolombSe() + $lastScale) & 0xff;
                $lastScale = $nextScale === 0 ? 8 : $nextScale;
            } else {
                $nextScale = $this->expGolombSe();
                $lastScale = $nextScale === 0 ? 8 : $nextScale;
            }
        }
    }

    /**
     * HRD参数跳过解析
     */
    protected function skipHrdParameters()
    {
        $cpb_cnt_minus1 = $this->expGolombUe();
        $this->getBits(4); // bit_rate_scale
        $this->getBits(4); // cpb_size_scale
        for ($i = 0; $i <= $cpb_cnt_minus1; $i++) {
            $this->expGolombUe();
            $this->expGolombUe();
            $this->getBits(1);
        }
        $this->getBits(5);
        $this->getBits(5);
        $this->getBits(5);
    }

    public function readData()
    {
        // AVC DecoderConfigurationRecord 头部 4字节固定
        $this->skipBits(8); // configurationVersion

        $confProfile = $this->getBits(8); // 外层配置头profile 废弃不用
        $this->skipBits(8); // profile_compatibility
        $this->level_idc = $this->getBits(8); // 原始level idc

        $naluLengthSize = ($this->getBits(8) & 0x03) + 1;
        $nbSps = $this->getBits(8) & 0x1F;

        if ($nbSps === 0) {
            return;
        }

        // 读取SPS NAL长度
        $this->getBits(16);
        $nalType = $this->getBits(8) & 0x1F;
        if ($nalType !== 0x07) {
            return;
        }

        // 真实SPS内部参数
        $profile_idc = $this->getBits(8);
        $this->profile = $profile_idc; // 赋值为真实编码档次

        $this->getBits(8); // constraint_set flags
        $this->getBits(8); // level_idc in SPS（仅元数据，以配置记录level为准）

        $this->expGolombUe(); // seq_parameter_set_id

        // 高档次扩展参数解析
        if (in_array($profile_idc, [100, 110, 122, 244, 44, 83, 86, 118])) {
            $cfIdc = $this->expGolombUe();
            if ($cfIdc === 3) {
                $this->getBits(1); // separate_colour_plane_flag
            }
            $this->expGolombUe(); // bit_depth_luma_minus8
            $this->expGolombUe(); // bit_depth_chroma_minus8
            $this->getBits(1); // qpprime_y_zero_transform_bypass_flag

            if ($this->getBits(1)) { // seq_scaling_matrix_present_flag
                $loopCount = $cfIdc !== 3 ? 8 : 12;
                for ($n = 0; $n < $loopCount; $n++) {
                    if ($this->getBits(1)) {
                        $this->scalingList($n < 6 ? 16 : 64);
                    }
                }
            }
        }

        $this->expGolombUe(); // log2_max_frame_num_minus4
        $picOrderCntType = $this->expGolombUe();

        // 关键修复：补齐 pic_order_cnt_type=2 空分支，防止比特偏移
        switch ($picOrderCntType) {
            case 0:
                $this->expGolombUe(); // max_pic_order_cnt_lsb
                break;
            case 1:
                $this->getBits(1); // delta_pic_order_always_zero_flag
                $this->expGolombUe(); // offset_for_non_ref_pic
                $this->expGolombUe(); // offset_for_top_to_bottom_field
                $numRefFrames = $this->expGolombUe();
                for ($n = 0; $n < $numRefFrames; $n++) {
                    $this->expGolombUe(); // offset_for_ref_frame
                }
                break;
            case 2:
                // 无任何数据读取，仅占位对齐比特流
                break;
        }

        // 统一读取参考帧数量
        $this->avc_ref_frames = $this->expGolombUe();

        $this->getBits(1); // gaps_in_frame_num_value_allowed_flag
        $picWidthMbsMinus1 = $this->expGolombUe();
        $picHeightMapMinus1 = $this->expGolombUe();

        $frameMbsOnlyFlag = $this->getBits(1);
        if (!$frameMbsOnlyFlag) {
            $this->getBits(1); // mb_adaptive_frame_field_flag
        }
        $this->getBits(1); // direct_8x8_inference_flag

        // 画面裁剪
        $cropFlag = $this->getBits(1);
        $cropLeft = $cropRight = $cropTop = $cropBottom = 0;
        if ($cropFlag) {
            $cropLeft = $this->expGolombUe();
            $cropRight = $this->expGolombUe();
            $cropTop = $this->expGolombUe();
            $cropBottom = $this->expGolombUe();
        }

        // 计算真实分辨率
        $this->width = ($picWidthMbsMinus1 + 1) * 16 - ($cropLeft + $cropRight) * 2;
        $this->height = (2 - $frameMbsOnlyFlag) * ($picHeightMapMinus1 + 1) * 16 - ($cropTop + $cropBottom) * 2;

        // 格式化level，同时兼容旧level字段
        $this->levelStr = sprintf("%d.%d", intval($this->level_idc / 10), $this->level_idc % 10);
        $this->level = $this->levelStr;

        // VUI参数处理
        $vuiPresent = $this->getBits(1);
        // 默认初始化SAR、帧率
        $this->sarNum = 1;
        $this->sarDen = 1;
        $this->sarRatio = 1.0;
        $this->frameRate = null;

        if ($vuiPresent === 1) {
            // 采样宽高比
            $aspectInfoFlag = $this->getBits(1);
            if ($aspectInfoFlag) {
                $aspectIdc = $this->getBits(8);
                if ($aspectIdc === 255) {
                    $this->sarNum = $this->getBits(16);
                    $this->sarDen = $this->getBits(16);
                } else {
                    $sarTable = [
                        [1, 1], [12, 11], [10, 11], [16, 11], [40, 33], [24, 11],
                        [20, 11], [32, 11], [80, 33], [18, 11], [15, 11], [64, 33],
                        [160, 99], [4, 3], [3, 2], [2, 1]
                    ];
                    if ($aspectIdc >= 0 && $aspectIdc < count($sarTable)) {
                        $this->sarNum = $sarTable[$aspectIdc][0];
                        $this->sarDen = $sarTable[$aspectIdc][1];
                    }
                }
                $this->sarRatio = $this->sarNum / $this->sarDen;
            }

            $this->getBits(1); // overscan_info_present_flag
            if ($this->getBits(1)) {
                $this->getBits(1); // overscan_appropriate_flag
            }

            $this->getBits(1); // video_signal_type_present_flag
            if ($this->getBits(1)) {
                $this->getBits(3); // video_format
                $this->getBits(1); // video_full_range_flag
                if ($this->getBits(1)) {
                    $this->getBits(8); // colour_primaries
                    $this->getBits(8); // transfer_characteristics
                    $this->getBits(8); // matrix_coeffs
                }
            }

            $this->getBits(1); // chroma_loc_info_present_flag
            if ($this->getBits(1)) {
                $this->expGolombUe();
                $this->expGolombUe();
            }

            // 时序帧率信息
            if ($this->getBits(1)) { // timing_info_present_flag
                $numUnitsInTick = $this->getBits(32);
                $timeScale = $this->getBits(32);
                $this->getBits(1); // fixed_frame_rate_flag
                if ($numUnitsInTick > 0 && $timeScale > 0) {
                    $calcFps = $timeScale / (2 * $numUnitsInTick);
                    // 合法帧率范围兜底
                    if ($calcFps >= 1 && $calcFps <= 120) {
                        $this->frameRate = $calcFps;
                    }
                }
            }

            // HRD跳过
            $nalHrdPresent = $this->getBits(1);
            if ($nalHrdPresent) {
                $this->skipHrdParameters();
            }
            $vclHrdPresent = $this->getBits(1);
            if ($vclHrdPresent) {
                $this->skipHrdParameters();
            }
            if ($nalHrdPresent || $vclHrdPresent) {
                $this->getBits(1); // low_delay_hrd_flag
            }

            $this->getBits(1); // pic_struct_present_flag
            if ($this->getBits(1)) { // bitstream_restriction_flag
                $this->getBits(1);
                $this->expGolombUe();
                $this->expGolombUe();
                $this->expGolombUe();
                $this->expGolombUe();
                $this->expGolombUe();
                $this->expGolombUe();
            }
        }

        // 全局兜底：非1:1强制修正，解决比特偏移读出错误SAR
        $validMin = 0.99;
        $validMax = 1.01;
        if ($this->sarRatio < $validMin || $this->sarRatio > $validMax) {
            $this->sarNum = 1;
            $this->sarDen = 1;
            $this->sarRatio = 1.0;
        }
    }
}