<?php
namespace Tests\Architecture;

use Tests\TestCase;
class ClusterDebugTest extends TestCase
{
    public function test_debug_violations(): void
    {
        $t=new \ReflectionClass(\Tests\Architecture\ModuleBoundariesTest::class);
        $instance=$t->newInstanceWithoutConstructor();
        $root=sys_get_temp_dir().'/cluster-debug-'.bin2hex(random_bytes(4));
        mkdir($root,0700,true);
        @mkdir("$root/Modules/WorkRecords/Features/Submit",0700,true);
        file_put_contents("$root/Modules/WorkRecords/Features/Submit/Handler.php","<?php\nnamespace Modules\\WorkRecords\\Features\\Submit;\nuse Modules\\Identity\\Domain\\User;\nfinal class Handler {}\n");
        $set=new \ReflectionMethod($instance,'setUp');
        $set->setAccessible(true);
        $set->invoke($instance);
        $v=new \ReflectionMethod($instance,'violationsIn');
        $v->setAccessible(true);
        $out=$v->invoke($instance,$root);
        $expected='WorkRecords may import Identity only through Contracts or Events.';
        // assertContains uses string match
        fwrite(STDERR,"isIn=".(in_array($expected,$out,true)?'yes':'no')."\n");
        fwrite(STDERR,"inHaystack=".(in_array($expected,$out,false)?'yes':'no')."\n");
        fwrite(STDERR,"contains=".(array_filter($out,fn($l)=>$l===$expected)?'yes':'no')."\n");
        $this->assertTrue(true);
    }
}
