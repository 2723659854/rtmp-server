<?php


namespace MediaServer\Rtmp;


use MediaServer\MediaReader\MetaDataFrame;
use \Exception;

/**
 * @purpose rtmp数据处理
 * @author yanglong
 */
trait RtmpDataHandlerTrait
{

    /**
     * 幀率設置
     * @throws Exception
     */
    public function rtmpDataHandler()
    {
        /** 获取当前的数据包 */
        $p = $this->currentPacket;
        //AMF0 数据解释
        /** 读取命令 */
        $dataMessage = RtmpAMF::rtmpDataAmf0Reader($p->payload);
        logger()->info("rtmpDataHandler {$dataMessage['cmd']} " . json_encode($dataMessage));
        /** 判断命令 */
        switch ($dataMessage['cmd']) {
            /** 设置数据格式 客戶端向服務端發送命令設置數據流 主要用來采集客户端的音频采样率和视频的尺寸和帧率 实际就是meta帧 */
            case '@setDataFrame':
                if (isset($dataMessage['dataObj'])) {
                    /** 音频采样频率 */
                    $this->audioSamplerate = $dataMessage['dataObj']['audiosamplerate'] ?? $this->audioSamplerate;
                    /** 声道信息 单声道还是双声道 */
                    $this->audioChannels = isset($dataMessage['dataObj']['stereo']) ? ($dataMessage['dataObj']['stereo'] ? 2 : 1) : $this->audioChannels;
                    /** 视频宽度 */
                    $this->videoWidth = $dataMessage['dataObj']['width'] ?? $this->videoWidth;
                    /** 视频高度 */
                    $this->videoHeight = $dataMessage['dataObj']['height'] ?? $this->videoHeight;
                    /** 视频帧率 */
                    $this->videoFps = $dataMessage['dataObj']['framerate'] ?? $this->videoFps;
                }
                /** 标记 已设置媒体元素 */
                $this->isMetaData = true;
                /** 解析命令 */
                $metaDataFrame = new MetaDataFrame(RtmpAMF::rtmpDATAAmf0Creator([
                    /** 设置元数据 */
                    'cmd' => 'onMetaData',
                    'dataObj' => $dataMessage['dataObj']
                ]));
                /** 保存命令 */
                $this->metaDataFrame = $metaDataFrame;
                /** 设置回调 on_frame事件 */
                /** 每一个推波数据流都绑定了on_frame 事件，每一个播放器也绑定了这个事件，只要有视频或者音频数据包被发送，都会触发这个事件，这个事件的作用是：给所有链接这个推流资源的链接推送数据 */
                $this->emit('on_frame', [$metaDataFrame, $this]);

            //播放类群发onMetaData
        }
    }
}