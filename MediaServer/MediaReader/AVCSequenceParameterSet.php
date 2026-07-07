<?php

namespace MediaServer\MediaReader;

use MediaServer\Utils\BitReader;

/**
 * @purpose H264视频SPS序列参数集解析（AVC Decoder Configuration Record）
 * @author  yanglong (修正版)
 */
class AVCSequenceParameterSet extends BitReader
{
    /** 真实SPS内部profile_idc 66=Baseline 77=Main 100=High */
    public $profile;

    /** 用于判断 Constrained Baseline */
    public $constraintSet1Flag = false;

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
         echo "profile_idc: {$this->profile} 名称: {$this->getAVCProfileName()}\n";
         echo "level: {$this->level}\n";
         echo "width: {$this->width} height: {$this->height}\n";
         echo "avc_ref_frames: {$this->avc_ref_frames}\n";
         echo "frameRate: " . var_export($this->frameRate, true) . "\n";
         echo "sarNum:{$this->sarNum} sarDen:{$this->sarDen} ratio:{$this->sarRatio}\n\n";
    }

    /**
     * 根据profile_idc返回档次名称
     */
    public function getAVCProfileName()
    {
        if ($this->profile == 66 && $this->constraintSet1Flag) {
            return 'Constrained Baseline';
        }
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
     * 缩放矩阵解析（H.264 7.3.2.1.1.1 及 7.4.2.1.1.1）
     * 修正：正确处理nextScale == 0终止条件
     */
    protected function scalingList($sizeOfScalingList)
    {
        $lastScale = 8;
        $nextScale = 8;
        for ($j = 0; $j < $sizeOfScalingList; $j++) {
            if ($nextScale != 0) {
                $deltaScale = $this->expGolombSe();
                $nextScale = ($lastScale + $deltaScale + 256) % 256;
                // useDefaultScalingMatrixFlag = ($j == 0 && $nextScale == 0);
            }
            // 若nextScale为0，则当前值沿用lastScale，且后续所有值都不再读取delta
            $lastScale = ($nextScale == 0) ? $lastScale : $nextScale;
        }
    }

    /**
     * HRD参数跳过解析（H.264 E.1.2）
     */
    protected function skipHrdParameters()
    {
        $cpb_cnt_minus1 = $this->expGolombUe();
        $this->getBits(4); // bit_rate_scale
        $this->getBits(4); // cpb_size_scale
        for ($i = 0; $i <= $cpb_cnt_minus1; $i++) {
            $this->expGolombUe(); // bit_rate_value_minus1
            $this->expGolombUe(); // cpb_size_value_minus1
            $this->getBits(1);    // cbr_flag
        }
        $this->getBits(5); // initial_cpb_removal_delay_length_minus1
        $this->getBits(5); // cpb_removal_delay_length_minus1
        $this->getBits(5); // dpb_output_delay_length_minus1
    }

    public function readData()
    {
        // ---- 1. 跳过 AVC DecoderConfigurationRecord 固定头 ----
        $this->skipBits(8); // configurationVersion = 1

        $confProfile = $this->getBits(8); // AVCProfileIndication（外层，不用）
        $this->skipBits(8); // profile_compatibility
        $this->level_idc = $this->getBits(8); // AVCLevelIndication（作为最终level）

        $naluLengthSize = ($this->getBits(8) & 0x03) + 1; // 未使用，仅消耗
        $nbSps = $this->getBits(8) & 0x1F;

        if ($nbSps === 0) {
            return;
        }

        // ---- 2. 定位到第一条 SPS NAL 单元 ----
        $this->getBits(16); // SPS长度
        $nalType = $this->getBits(8) & 0x1F; // NAL单元类型
        if ($nalType !== 0x07) {
            return; // 不是SPS，终止解析
        }

        // ---- 3. 开始解析 SPS 语法（H.264 7.3.2.1.1） ----
        $profile_idc = $this->getBits(8);
        $this->profile = $profile_idc;

        $constraintByte = $this->getBits(8); // constraint_set0_flag ~ constraint_set5_flag + 2 reserved
        $this->constraintSet1Flag = (($constraintByte >> 6) & 1) == 1;
        $this->getBits(8); // level_idc（SPS内部，实际使用外层）

        $this->expGolombUe(); // seq_parameter_set_id

        // 高档次/扩展档次额外字段
        if (in_array($profile_idc, [100, 110, 122, 244, 44, 83, 86, 118])) {
            $cfIdc = $this->expGolombUe(); // chroma_format_idc
            if ($cfIdc === 3) {
                $this->getBits(1); // separate_colour_plane_flag
            }
            $this->expGolombUe(); // bit_depth_luma_minus8
            $this->expGolombUe(); // bit_depth_chroma_minus8
            $this->getBits(1);    // qpprime_y_zero_transform_bypass_flag

            if ($this->getBits(1)) { // seq_scaling_matrix_present_flag
                $loopCount = ($cfIdc !== 3) ? 8 : 12;
                for ($n = 0; $n < $loopCount; $n++) {
                    if ($this->getBits(1)) { // seq_scaling_list_present_flag[i]
                        $this->scalingList($n < 6 ? 16 : 64);
                    }
                }
            }
        }

        $this->expGolombUe(); // log2_max_frame_num_minus4
        $picOrderCntType = $this->expGolombUe();

        // 图像顺序计数处理
        switch ($picOrderCntType) {
            case 0:
                $this->expGolombUe(); // log2_max_pic_order_cnt_lsb_minus4
                break;
            case 1:
                $this->getBits(1);    // delta_pic_order_always_zero_flag
                $this->expGolombUe(); // offset_for_non_ref_pic
                $this->expGolombUe(); // offset_for_top_to_bottom_field
                $numRefFramesInPicOrderCntCycle = $this->expGolombUe();
                for ($n = 0; $n < $numRefFramesInPicOrderCntCycle; $n++) {
                    $this->expGolombUe(); // offset_for_ref_frame[i]
                }
                break;
            case 2:
                // 无数据
                break;
        }

        $this->avc_ref_frames = $this->expGolombUe(); // max_num_ref_frames

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
            $cropLeft   = $this->expGolombUe();
            $cropRight  = $this->expGolombUe();
            $cropTop    = $this->expGolombUe();
            $cropBottom = $this->expGolombUe();
        }

        // 计算真实分辨率
        $this->width  = ($picWidthMbsMinus1 + 1) * 16 - ($cropLeft + $cropRight) * 2;
        $this->height = (2 - $frameMbsOnlyFlag) * ($picHeightMapMinus1 + 1) * 16 - ($cropTop + $cropBottom) * 2;

        // 格式化level
        $this->levelStr = sprintf("%d.%d", intval($this->level_idc / 10), $this->level_idc % 10);
        $this->level    = $this->levelStr;

        // ---- 4. VUI 参数解析（H.264 附录E） ----
        $vuiPresent = $this->getBits(1);
        $this->sarNum = 1;
        $this->sarDen = 1;
        $this->sarRatio = 1.0;
        $this->frameRate = null;

        if ($vuiPresent === 1) {
            // 4.1 aspect_ratio_info_present_flag
            if ($this->getBits(1)) {
                $aspectIdc = $this->getBits(8);
                if ($aspectIdc === 255) {
                    $this->sarNum = $this->getBits(16);
                    $this->sarDen = $this->getBits(16);
                } else {
                    // H.264 表 E-1（索引 0~16，共 17 个，idc=0 时表示未指定，此处保持默认1:1）
                    $sarTable = [
                        1 => [1, 1],   2 => [12, 11], 3 => [10, 11],
                        4 => [16, 11], 5 => [40, 33], 6 => [24, 11],
                        7 => [20, 11], 8 => [32, 11], 9 => [80, 33],
                        10 => [18, 11], 11 => [15, 11], 12 => [64, 33],
                        13 => [160, 99], 14 => [4, 3], 15 => [3, 2],
                        16 => [2, 1]
                    ];
                    if (isset($sarTable[$aspectIdc])) {
                        $this->sarNum = $sarTable[$aspectIdc][0];
                        $this->sarDen = $sarTable[$aspectIdc][1];
                    }
                }
                if ($this->sarDen != 0) {
                    $this->sarRatio = $this->sarNum / $this->sarDen;
                }
            }

            // 4.2 overscan_info_present_flag
            $overscanFlag = $this->getBits(1);
            if ($overscanFlag) {
                $this->getBits(1); // overscan_appropriate_flag
            }

            // 4.3 video_signal_type_present_flag
            $vsFlag = $this->getBits(1);
            if ($vsFlag) {
                $this->getBits(3); // video_format
                $this->getBits(1); // video_full_range_flag
                $cdescFlag = $this->getBits(1);
                if ($cdescFlag) {
                    $this->getBits(8); // colour_primaries
                    $this->getBits(8); // transfer_characteristics
                    $this->getBits(8); // matrix_coefficients
                }
            }

            // 4.4 chroma_loc_info_present_flag
            $chromaLocFlag = $this->getBits(1);
            if ($chromaLocFlag) {
                $this->expGolombUe(); // chroma_sample_loc_type_top_field
                $this->expGolombUe(); // chroma_sample_loc_type_bottom_field
            }

            // 4.5 timing_info_present_flag
            $timingFlag = $this->getBits(1);
            if ($timingFlag) {
                $numUnitsInTick = $this->getBits(32);
                $timeScale      = $this->getBits(32);
                $this->getBits(1); // fixed_frame_rate_flag
                if ($numUnitsInTick > 0 && $timeScale > 0) {
                    $calcFps = $timeScale / (2 * $numUnitsInTick);
                    if ($calcFps >= 1 && $calcFps <= 120) {
                        $this->frameRate = $calcFps;
                    }
                }
            }

            // 4.6 NAL HRD & VCL HRD
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

            // 4.7 pic_struct_present_flag & bitstream_restriction_flag
            $this->getBits(1); // pic_struct_present_flag（直接消耗）
            $bitRestrictFlag = $this->getBits(1);
            if ($bitRestrictFlag) {
                $this->getBits(1); // motion_vectors_over_pic_boundaries_flag
                $this->expGolombUe(); // max_bytes_per_pic_denom
                $this->expGolombUe(); // max_bits_per_mb_denom
                $this->expGolombUe(); // log2_max_mv_length_horizontal
                $this->expGolombUe(); // log2_max_mv_length_vertical
                $this->expGolombUe(); // max_num_reorder_frames
                $this->expGolombUe(); // max_dec_frame_buffering
            }
        }

        // 注：已移除原全局SAR强制修正，现在完全信任解析结果。
    }
}