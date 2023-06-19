<?php

namespace App\Controller;

use App\DTO\UserDto;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use JMS\Serializer\Serializer;
use JMS\Serializer\SerializerBuilder;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Security;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/v1')]
class APIController extends AbstractController
{
    private ValidatorInterface $validator;

    private Serializer $serializer;

    private UserPasswordHasherInterface $hasher;

    public function __construct(
        ValidatorInterface          $validator,
        UserPasswordHasherInterface $hasher
    )
    {
        $this->validator = $validator;
        $this->serializer = SerializerBuilder::create()->build();
        $this->hasher = $hasher;
    }

    #[OA\Post (
        path: '/api/v1/auth',
        description: "Входные данные - email и пароль.\nВыходные данные - JSON с JWT-токеном в случае успеха, JSON с ошибками в случае возникновения ошибок",
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
                new OA\Property(property: 'token', type: 'string')
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
                new OA\Property(property: 'ROLES', type: 'array', items: new OA\Items(type: "string"))
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
        $errs = $this->validator->validate($dto);

        if (count($errs) > 0) {
            $jsonErrors = [];
            foreach ($errs as $error) {
                $jsonErrors[$error->getPropertyPath()] = $error->getMessage();
            }
            return new JsonResponse(['error' => $jsonErrors], Response::HTTP_BAD_REQUEST);
        }

        if ($repo->findOneBy(['email' => $dto->username])) {
            return new JsonResponse(['error' => 'Email уже используется.'], Response::HTTP_CONFLICT);
        }
        $user = User::formDTO($dto);
        $user->setPassword(
            $this->hasher->hashPassword($user, $user->getPassword())
        );
        $repo->save($user, true);
        return new JsonResponse([
            'token' => $jwtManager->create($user),
            'roles' => $user->getRoles(),
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
    #[\Nelmio\ApiDocBundle\Annotation\Security(name: 'Bearer')]
    public function currentUser(): JsonResponse
    {
        return new JsonResponse([
            'code' => 200,
            'username' => $this->getUser()->getEmail(),
            'roles' => $this->getUser()->getRoles(),
            'balance' => $this->getUser()->getBalance(),
        ], Response::HTTP_OK);
    }


}
