<?php
// Exercise the actual SDK and adapter with a transport that does not retain the
// request's PSR body. Retaining it in MockHandler masks the double-close bug.
use TypechoPlugin\SoraglassGallery\Storage\Cos;
use GuzzleHttp\Psr7\Response;
foreach ([68, 5242881] as $bytes) {
    $protocolFile=tempnam(sys_get_temp_dir(),'sg-cos-test-');
    file_put_contents($protocolFile,str_repeat('p',$bytes));
    $hash=hash_file('sha256',$protocolFile); $writes=0; $public=false;
    $adapter=new Cos(['region'=>'ap-guangzhou','bucket'=>'test-1250000000','keyId'=>'test-id','keySecret'=>'test-secret','endpoint'=>'','domain'=>'','timeout'=>30]);
    $sdk=(new ReflectionProperty(Cos::class,'client'))->getValue($adapter);
    $sdk->getHttpClient()->getConfig('handler')->setHandler(static function ($request,$options) use ($bytes,$hash,&$writes,&$public) {
        parse_str($request->getUri()->getQuery(),$query);
        if ($request->getMethod()==='HEAD') {
            $response=new Response(200,['Content-Length'=>$bytes,'Content-Type'=>'image/jpeg','x-cos-meta-sg-sha256'=>$hash]);
        } elseif (array_key_exists('uploads',$query)) {
            $public=$request->getHeaderLine('x-cos-acl')==='public-read' && $request->getHeaderLine('x-cos-meta-sg-sha256')===$hash;
            $response=new Response(200,[], '<InitiateMultipartUploadResult><Bucket>test-1250000000</Bucket><Key>gallery/test.jpg</Key><UploadId>test-upload</UploadId></InitiateMultipartUploadResult>');
        } elseif (isset($query['uploadId']) && $request->getMethod()==='POST') {
            $response=new Response(200,[], '<CompleteMultipartUploadResult><Bucket>test-1250000000</Bucket><Key>gallery/test.jpg</Key><ETag>test-complete</ETag></CompleteMultipartUploadResult>');
        } else {
            $writes++;
            if (!isset($query['uploadId'])) $public=$request->getHeaderLine('x-cos-acl')==='public-read' && $request->getHeaderLine('x-cos-meta-sg-sha256')===$hash;
            $response=new Response(200,['ETag'=>'"test-part"']);
        }
        return \GuzzleHttp\Promise\Create::promiseFor($response);
    });
    try {
        $adapter->upload($protocolFile,'gallery/test.jpg','image/jpeg',$hash);
        $object=$adapter->inspect('gallery/test.jpg');
        check($object['bytes']===$bytes && $object['hash']===$hash && $public && $writes>0, 'actual COS SDK ' . ($bytes<5242880?'single':'multipart') . ' upload + HEAD completes without double-close');
        check($adapter->thumbnail('gallery/test.jpg')===$adapter->url('gallery/test.jpg'),'COS image URL has no thumbnail query parameters');
        $sdk->getHttpClient()->getConfig('handler')->setHandler(static fn($request,$options)=>\GuzzleHttp\Promise\Create::promiseFor(new Response(403,[], '<Error><Code>AccessDenied</Code><Message>test</Message></Error>')));
        $denied=false;
        try { $adapter->inspect('gallery/test.jpg'); } catch (\Qcloud\Cos\Exception\ServiceResponseException $e) { $denied=true; }
        check($denied,'actual SDK HEAD 403 never treated as missing');
    } finally { unlink($protocolFile); }
}
