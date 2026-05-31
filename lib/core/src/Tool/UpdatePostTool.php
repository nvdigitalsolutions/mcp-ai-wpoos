<?php
/** @package Oos\Core @since 1.0.0 @license MIT */
declare(strict_types=1);
namespace Oos\Core\Tool;
use Oos\Core\Domain\Contract\ErrorFactoryInterface;
use Oos\Core\Domain\Contract\ContentStoreInterface;
use Oos\Core\Domain\Entity\UpdateContentCommand;
use Oos\Core\Domain\Error\AccessDeniedException;
use Oos\Core\Domain\Error\NotFoundException;
class UpdatePostTool extends AbstractTool {
    public function __construct(ErrorFactoryInterface $e,private readonly ContentStoreInterface $c){parent::__construct($e);}
    public function getSlug(): string { return 'update_post'; }
    public function getName(): string { return 'Update Post'; }
    public function getDescription(): string { return 'Updates an existing content item. Only provided fields are changed.'; }
    public function getParametersSchema(): array { return ['type'=>'object','properties'=>['post_id'=>['type'=>'integer','description'=>'ID of the post to update','minimum'=>1],'title'=>['type'=>'string','description'=>'New title'],'content'=>['type'=>'string','description'=>'New body content'],'status'=>['type'=>'string','description'=>'New status','enum'=>['publish','draft','private','pending']],'excerpt'=>['type'=>'string','description'=>'New excerpt']],'required'=>['post_id'],'additionalProperties'=>false]; }
    public function getRequiredCapability(): string { return 'edit_posts'; }
    public function execute(array $arguments=[],array $context=[]): mixed {
        $postId = $this->intParam($arguments,'post_id'); if ($postId<=0) return $this->errors->validationFailed('post_id is required.',['post_id'=>['Post ID is required.']]);
        $userId = $context['user_id']??0; if ($userId<=0) return $this->errors->forbidden('You must be logged in.');
        $cmd = new UpdateContentCommand(title:$this->stringParam($arguments,'title')?:null,content:$this->stringParam($arguments,'content')?:null,status:$this->stringParam($arguments,'status')?:null,excerpt:$this->stringParam($arguments,'excerpt')?:null,userId:$userId);
        try {
            $item = $this->c->update($postId,$cmd);
            return $this->success('Post updated.',$item->jsonSerialize());
        } catch (NotFoundException $e) { return $this->errors->notFound($e->getMessage()); }
        catch (AccessDeniedException $e) { return $this->errors->forbidden($e->getMessage()); }
        catch (\Throwable $e) { return $this->errors->create('update_failed',$e->getMessage()); }
    }
}
