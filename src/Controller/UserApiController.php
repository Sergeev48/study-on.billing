<?php

namespace App\Controller;

use App\DTO\UserDto;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\ControllerValidator;
use App\Service\PaymentService;
use JMS\Serializer\Serializer;
use JMS\Serializer\SerializerBuilder;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Nelmio\ApiDocBundle\Annotation\Security;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Gesdinet\JWTRefreshTokenBundle\Generator\RefreshTokenGeneratorInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;

#[Route('/api/v1')]
class UserApiController extends AbstractController
{
    private ValidatorInterface $validator;
    private Serializer $serializer;
    private UserPasswordHasherInterface $hasher;
    private RefreshTokenGeneratorInterface $refreshTokenGenerator;
    private RefreshTokenManagerInterface $refreshTokenManager;
    private ControllerValidator $controllerValidator;
    private PaymentService $paymentService;

    public function __construct(
        ValidatorInterface          $validator,
        UserPasswordHasherInterface $hasher,
        RefreshTokenGeneratorInterface $refreshTokenGenerator,
        RefreshTokenManagerInterface $refreshTokenManager,
        ControllerValidator $controllerValidator,
        PaymentService $paymentService
    )
    {
        $this->validator = $validator;
        $this->serializer = SerializerBuilder::create()->build();
        $this->hasher = $hasher;
        $this->refreshTokenGenerator = $refreshTokenGenerator;
        $this->refreshTokenManager = $refreshTokenManager;
        $this->controllerValidator = $controllerValidator;
        $this->paymentService = $paymentService;



    }

    #[OA\Post (
        path: '/api/v1/auth',
        description: "Входные данные - email и пароль.\nВыходные данные - JSON с JWT и refresh токеном в случае успеха, JSON с ошибками в случае возникновения ошибок",
        summary: "Авторизация пользователя"
    )]
    #[OA\RequestBody (
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'username', type: 'string'),
                new OA\Property(property: 'password', type: 'string')
            ],
            type: 'object'
        )
    )]
    #[OA\Response(
        response: 201,
        description: 'Успешная авторизация',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'token', type: 'string'),
                new OA\Property(property: 'refresh_token', type: 'string')

            ],
            type: 'object'
        )
    )]
    #[OA\Response(
        response: 401,
        description: 'Неправильные данные',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'code', type: 'string', example: 401),
                new OA\Property(property: 'message', type: 'string', example: 'Invalid credentials.')
            ],
            type: 'object'
        )
    )]
    #[OA\Response(
        response: 500,
        description: 'Неизвестная ошибка',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'code', type: 'string'),
                new OA\Property(property: 'message', type: 'string')
            ],
            type: 'object'
        )
    )]
    #[OA\Parameter (
        name: 'body',
        description: 'JSON Payload',
        in: 'query',
    )]
    #[OA\Tag(
        name: "User"
    )]
    #[Route('/auth', name: 'api_auth', methods: ['POST'])]
    public function auth(): void
    {

    }

    #[OA\Post (
        path: '/api/v1/register',
        description: "Входные данные - email и пароль.\nВыходные данные - JSON с JWT-токеном, роли пользователя и его баланс в случае успеха, JSON с ошибками в случае возникновения ошибок",
        summary: "Регистрация пользователя"
    )]
    #[OA\RequestBody (
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'username', type: 'string'),
                new OA\Property(property: 'password', type: 'string')
            ],
            type: 'object'
        )
    )]
    #[OA\Response(
        response: 201,
        description: 'Успешная регистрация',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'token', type: 'string'),
                new OA\Property(property: 'ROLES', type: 'array', items: new OA\Items(type: "string")),
                new OA\Property(property: 'balance', type: 'integer', example: 0),
                new OA\Property(property: 'refresh_token', type: 'string')
            ],
            type: 'object'
        )
    )]
    #[OA\Response(
        response: 401,
        description: 'Регистрация с неправильными данными',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'code', type: 'string', example: 401),
                new OA\Property(property: 'errors', type: 'array',
                    items: new OA\Items(type: "string"))
            ],
            type: 'object'
        )
    )]
    #[OA\Response(
        response: 500,
        description: 'Неизвестная ошибка',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'code', type: 'string'),
                new OA\Property(property: 'message', type: 'string')
            ],
            type: 'object'
        )
    )]
    #[OA\Parameter (
        name: 'body',
        description: 'JSON Payload',
        in: 'query',
    )]
    #[OA\Tag(
        name: "User"
    )]
    #[Route('/register', name: 'api_register', methods: ['POST'])]
    public function register(
        Request                  $req,
        UserRepository           $repo,
        JWTTokenManagerInterface $jwtManager
    ): JsonResponse
    {
        $dto = $this->serializer->deserialize($req->getContent(), UserDTO::class, 'json');
        $errors = $this->validator->validate($dto);

        $dataErrorResponse = $this->controllerValidator->validateDto($errors);
        if ($dataErrorResponse !== null) {
            return $dataErrorResponse;
        }
        $user = $repo->findOneBy(['email' => $dto->username]);
        $uniqueErrorResponse = $this->controllerValidator->validateRegistrationUnique($user);
        if ($uniqueErrorResponse !== null) {
            return $uniqueErrorResponse;
        }
        $user = User::formDTO($dto);
        $this->paymentService->deposit($user, $_ENV['CLIENT_MONEY']);
        $user->setPassword(
            $this->hasher->hashPassword($user, $user->getPassword())
        );
        $repo->save($user, true);
        $refreshToken = $this->refreshTokenGenerator->createForUserWithTtl(
            $user,
            (new \DateTime())->modify('+1 month')->getTimestamp()
        );
        $this->refreshTokenManager->save($refreshToken);
        return new JsonResponse([
            'token' => $jwtManager->create($user),
            'roles' => $user->getRoles(),
            'balance' => $user->getBalance(),
            'refresh_token' => $refreshToken->getRefreshToken()
        ], Response::HTTP_CREATED);
    }

    #[OA\Get (
        path: '/api/v1/users/current',
        description: "Входные данные - JWT-токен.\nВыходные данные - электронная почта, роли пользователя и его баланс в случае успеха, JSON с ошибками в случае возникновения ошибок",
        summary: "Получение текущего пользователя"
    )]
    #[OA\Response(
        response: 201,
        description: 'Успешное получение пользователя',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'code', type: 'string', example: 200),
                new OA\Property(property: 'username', type: 'string'),
                new OA\Property(property: 'ROLES', type: 'array', items: new OA\Items(type: "string")),
                new OA\Property(property: 'balance', type: 'integer', example: 0)
            ],
            type: 'object'
        )
    )]
    #[OA\Response(
        response: 401,
        description: 'Ввод неправильного JWT-токена',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'code', type: 'string', example: 401),
                new OA\Property(property: 'errors', type: 'string', example: "Invalid JWT Token")
            ],
            type: 'object'
        )
    )]
    #[OA\Response(
        response: 500,
        description: 'Неизвестная ошибка',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'code', type: 'string'),
                new OA\Property(property: 'message', type: 'string')
            ],
            type: 'object'
        )
    )]
    #[OA\Parameter (
        name: 'body',
        description: 'JSON Payload',
        in: 'query',
    )]
    #[OA\Tag(
        name: "User"
    )]
    #[Route('/users/current', name: 'api_current', methods: ['GET'])]
    #[Security(name: "Bearer")]
    public function getCurrentUser(): JsonResponse
    {
        $errorResponse = $this->controllerValidator->validateGetCurrentUser($this->getUser());
        if ($errorResponse !== null) {
            return $errorResponse;
        }
        return new JsonResponse([
            'code' => 200,
            'username' => $this->getUser()->getEmail(),
            'roles' => $this->getUser()->getRoles(),
            'balance' => $this->getUser()->getBalance(),
        ], Response::HTTP_OK);
    }

    #[Route('/token/refresh', name: 'api_refresh', methods: ['POST'])]

    #[OA\Post(
        path: '/api/v1/token/refresh',
        description: "Входные данные - refresh-токен.
        \nВыходные данные - JSON с JWT-токеном и refresh-токеном в случае успеха, 
        JSON с ошибками в случае возникновения ошибок",
        summary: "Обновление JWT-токена"
    )]

    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'refresh_token', type: 'string'),
            ],
            type: 'object'
        )
    )]

    #[OA\Response(
        response: 201,
        description: 'Успешное получение токена.',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'token', type: 'string'),
                new OA\Property(property: 'refresh_token', type: 'string')
            ],
            type: 'object'
        )
    )]

    #[OA\Response(
        response: 401,
        description: 'Ошибка в refresh-токене.',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'code', type: 'string', example: 401),
                new OA\Property(property: 'message', type: 'string', example: 'JWT Refresh Token Not Found')
            ],
            type: 'object'
        )
    )]

    #[OA\Response(
        response: 500,
        description: 'Неизвестная ошибка',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'code', type: 'string'),
                new OA\Property(property: 'message', type: 'string')
            ],
            type: 'object'
        )
    )]

    #[OA\Tag(
        name: "User"
    )]

    public function refresh(): void
    {
    }


}
