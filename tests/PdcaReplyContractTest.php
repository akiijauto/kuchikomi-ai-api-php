<?php
declare(strict_types=1);
namespace App\Tests;

require_once __DIR__.'/Fake/OfflineCurl.php';

use App\Reply\ClaudeGenerator;
use App\Reply\OfflineCurlFixture;
use App\Reply\Review;
use App\Store\Profile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('pdca-contract')]
final class PdcaReplyContractTest extends TestCase
{
    public static function cases(): array
    {
        $valid=[['label'=>'標準','text'=>'合成本文1'],['label'=>'具体的','text'=>'合成本文2'],['label'=>'簡潔','text'=>'合成本文3']];
        $with=static fn(string $field,string $value):array=>[array_replace($valid[0],[$field=>$value]),$valid[1],$valid[2]];
        return [
            '1案'=>[array_slice($valid,0,1),false], '2案'=>[array_slice($valid,0,2),false],
            '4案'=>[[...$valid,$valid[0]],false],
            '空ラベル'=>[$with('label',' '),false], '空本文'=>[$with('text',"\n\t "),false],
            '全角空白ラベル'=>[$with('label','　'),false], '全角空白本文'=>[$with('text','　'),false],
            '16文字ラベル'=>[$with('label',str_repeat('日',16)),false],
            '正常3案'=>[$valid,true], '15文字ラベル'=>[$with('label',str_repeat('日',15)),true],
        ];
    }

    #[DataProvider('cases')]
    public function test生成結果の契約(array $replies,bool $valid):void
    {
        OfflineCurlFixture::$replies=$replies;
        OfflineCurlFixture::$calls=0;
        $generator=new ClaudeGenerator('dummy-key');
        $profile=new Profile('合成店舗','飲食店','polite','合成署名','free');
        if (!$valid) $this->expectException(\RuntimeException::class);
        $result=$generator->generate($profile,new Review('合成の口コミ',5));
        self::assertFalse($result->mock);
        self::assertCount(3,$result->replies);
        self::assertSame(1,OfflineCurlFixture::$calls);
        foreach($result->replies as $i=>$reply){
            self::assertSame($replies[$i]['label'],$reply->style);
            self::assertSame($replies[$i]['text']."\n\n合成署名",$reply->text);
        }
    }
}
