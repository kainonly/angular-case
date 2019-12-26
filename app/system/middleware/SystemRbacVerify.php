<?php
declare (strict_types=1);

namespace app\system\middleware;

use Closure;
use Exception;
use think\Request;
use think\Response;
use think\helper\Str;
use app\system\redis\AclRedis;
use app\system\redis\RoleRedis;
use think\support\facade\Context;

class SystemRbacVerify
{
    private $except_prefix = [
        'valided'
    ];

    /**
     * @param Request $request
     * @param Closure $next
     * @return Response
     * @throws Exception
     */
    public function handle(Request $request, Closure $next): Response
    {
        $controller = Str::snake($request->controller(), '_');
        $action = Str::lower($request->action());
        foreach ($this->except_prefix as $value) {
            if (strpos($action, $value) !== false) {
                return $next($request);
            }
        }
        $roleKey = Context::get('auth')->role;
        $roleLists = [];
        foreach ($roleKey as $value) {
            array_push($roleLists, ...RoleRedis::create()->get($value, 'acl'));
        }
        rsort($roleLists);
        $policy = null;
        foreach ($roleLists as $k => $value) {
            [$roleController, $roleAction] = explode(':', Str::lower($value));
            if ($roleController == $controller) {
                $policy = $roleAction;
                break;
            }
        }
        if (is_null($policy)) {
            return json([
                'error' => 1,
                'msg' => 'rbac invalid, policy is empty',
            ]);
        }
        $aclLists = array_map(function ($value) {
            return Str::lower($value);
        }, AclRedis::create()->get($controller, (int)$policy));
        if (empty($aclLists)) {
            return json([
                'error' => 1,
                'msg' => 'rbac invalid, acl is empty'
            ]);
        }
        return in_array($action, $aclLists) ? $next($request) : json([
            'error' => 1,
            'msg' => 'rbac invalid, access denied'
        ]);
    }
}
