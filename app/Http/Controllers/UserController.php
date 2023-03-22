<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Cashier\Cashier;
use Throwable;

class UserController extends Controller
{
    public function create(Request $request)
    {
        DB::beginTransaction();
        try {
            $user = new User();
            $user->fill($request->all());
            $user->saveOrFail();
            $user->createAsStripeCustomer();
            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Cliente creado correctamente',
                'user' => $user,
            ]);
        } catch (Throwable $th) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al borrar cliente',
                'errorFile' => $th->getFile(),
                'errorLine' => $th->getLine(),
                'errorMessage' => $th->getMessage(),
            ]);
        }
    }

    public function update(Request $request, $customerId)
    {
        DB::beginTransaction();
        try {
            $user = User::findOrFail($customerId);
            $user->name = $request->name;
            $user->saveOrFail();

            $options = ['name' => $request->name];
            $user->updateStripeCustomer($options);

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Cliente actualizado correctamente',
                'user' => $user,
            ]);
        } catch (Throwable $th) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar cliente',
                'errorFile' => $th->getFile(),
                'errorLine' => $th->getLine(),
                'errorMessage' => $th->getMessage(),
            ]);
        }
    }

    public function updateBridge(Request $request, $customerId)
    {
        DB::beginTransaction();
        try {
            $user = User::findOrFail($customerId);
            $stripeCustomer = $user->asStripeCustomer();

            $user->name = $request->name;
            $stripeCustomer->name = $request->name;

            $user->saveOrFail();
            $stripeCustomer->save();

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Cliente actualizado correctamente',
                'user' => $user,
            ]);
        } catch (Throwable $th) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar cliente',
                'errorFile' => $th->getFile(),
                'errorLine' => $th->getLine(),
                'errorMessage' => $th->getMessage(),
            ]);
        }
    }

    public function updateSync(Request $request, $customerId)
    {
        DB::beginTransaction();
        try {
            $user = User::findOrFail($customerId);
            $user->name = $request->name;
            $user->saveOrFail();
            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Cliente actualizado correctamente',
                'user' => $user,
            ]);
        } catch (Throwable $th) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar cliente',
                'errorFile' => $th->getFile(),
                'errorLine' => $th->getLine(),
                'errorMessage' => $th->getMessage(),
            ]);
        }
    }


    public function delete($customerId)
    {
        DB::beginTransaction();
        try {

            // $user = User::findOrFail($customerId);
            // $stripeCustomer = $user->asStripeCustomer();
            // $stripeCustomer->delete();
            // $user->delete();

            $user = User::findOrFail($customerId);
            Cashier::stripe()->customers->delete($user->stripe_id, []);
            $user->delete();

            Cashier::stripe()->paymentIntents->create([
                'amount' => 2000,
                'currency' => 'mxn',
                'automatic_payment_methods' => [
                    'enabled' => true,
                ],
            ]);

            $card = Cashier::stripe()->paymentMethods->create([
                'type' => 'card',
                'card' => [
                    'number' => '4242424242424242',
                    'exp_month' => 8,
                    'exp_year' => 2020,
                    'cvc' => '314',
                ],
            ]);

            $user->addPaymentMethod($card->id);

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Cliente borrado correctamente',
            ]);
        } catch (Throwable $th) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al borrar cliente',
                'errorFile' => $th->getFile(),
                'errorLine' => $th->getLine(),
                'errorMessage' => $th->getMessage(),
            ]);
        }
    }

    public function upsert(Request $request, $customerId = 0)
    {
        DB::beginTransaction();
        try {
            $user = [];
            foreach ($request->all() as $parameter => $value) {
                if (isset($value)) $user += [$parameter => $value];
            }
            $user = User::updateOrCreate(['id' => $customerId], $user);
            $user->saveOrFail();
            if (!$user->hasStripeId()) $user->createAsStripeCustomer();
            DB::commit();
            return response()
                ->json([
                    'success' => true,
                    'message' => 'Usuario registrado correctamente'
                ]);
        } catch (Throwable $th) {
            DB::rollback();
            return response()
                ->json([
                    'success' => false,
                    'message' => 'Error al crear usuario',
                    'error_file' => $th->getFile(),
                    'error_line' => $th->getLine(),
                    'error_message' => $th->getMessage(),
                ]);
        }
    }

    public function createCard(Request $request, $customerId)
    {
        try {
            $user = User::findOrFail($customerId);
            $paymentMethod = Cashier::stripe()->paymentMethods->create($request->all());

            $user->addPaymentMethod($paymentMethod);

            $user->updateDefaultPaymentMethodFromStripe();

            if (!$user->hasDefaultPaymentMethod()) {
                $user->updateDefaultPaymentMethod($paymentMethod);
            }

            return response()
                ->json([
                    'success' => true,
                    'message' => 'Tarjeta creada correctamente'
                ]);
        } catch (Throwable $th) {
            DB::rollback();
            return response()->json([
                'success' => false,
                'message' => 'Error al crear tarjeta',
                'error_file' => $th->getFile(),
                'error_line' => $th->getLine(),
                'error_message' => $th->getMessage(),
            ]);
        }
    }

    public function getCards($customerId)
    {
        try {
            $user = User::findOrFail($customerId);
            if (!$user->hasPaymentMethod()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tiene tarjetas aun'
                ]);
            }
            return response()->json([
                'cards' => $user->paymentMethods(),
                'success' => true,
                'message' => 'Tarjeta creada correctamente'
            ]);
        } catch (Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Error al encontrar tarjetas',
                'error_file' => $th->getFile(),
                'error_line' => $th->getLine(),
                'error_message' => $th->getMessage(),
            ]);
        }
    }

    public function getCardDefault($customerId)
    {
        try {
            $user = User::findOrFail($customerId);
            $user->updateDefaultPaymentMethodFromStripe();
            if (!$user->hasPaymentMethod()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tiene tarjetas registradas'
                ]);
            } else if (!$user->hasDefaultPaymentMethod()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No existe tarjeta por defecto'
                ]);
            }
            return response()->json([
                'card' => $user->defaultPaymentMethod()->card,
                'success' => true,
                'message' => 'Tarjeta encontrada'
            ]);
        } catch (Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Error al encontrar tarjeta por defecto',
                'error_file' => $th->getFile(),
                'error_line' => $th->getLine(),
                'error_message' => $th->getMessage(),
            ]);
        }
    }

    public function paymentPMDefault(Request $request, $customerId)
    {
        try {
            $user = User::findOrFail($customerId);
            $user->updateDefaultPaymentMethodFromStripe();
            if (!$user->hasPaymentMethod()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tiene tarjetas registradas'
                ]);
            } else if (!$user->hasDefaultPaymentMethod()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No existe tarjeta por defecto'
                ]);
            }
            $user->charge(($request->amount * 100), $user->defaultPaymentMethod()->id);
            return response()->json([
                'success' => true,
                'message' => 'Pago realizado correctamente'
            ]);
        } catch (Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Error al hacer el pago',
                'error_file' => $th->getFile(),
                'error_line' => $th->getLine(),
                'error_message' => $th->getMessage(),
            ]);
        }
    }

    public function paymentGuest(Request $request)
    {
        try {
            $paymentMethod = Cashier::stripe()
                ->paymentMethods
                ->create(['type' => $request->type, 'card' => $request->card, 'billing_details' => ['email' => $request->email]]);

            (new User())->charge(($request->amount * 100), $paymentMethod->id);
            return response()->json([
                'success' => true,
                'message' => 'Pago de invitado realizado correctamente'
            ]);
        } catch (Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Error al hacer el pago',
                'error_file' => $th->getFile(),
                'error_line' => $th->getLine(),
                'error_message' => $th->getMessage(),
            ]);
        }
    }

    public function paymentIntent(Request $request, $customerId)
    {
        try {
            $user = User::findOrFail($customerId);

            $paymentMethod = Cashier::stripe()->paymentMethods->create(['type' => $request->type, 'card' => $request->card]);

            $paymentIntent = Cashier::stripe()->paymentIntents->create([
                'amount' => $request->amount * 100,
                'currency' => 'mxn',
                'payment_method' => $paymentMethod,
                'customer' => $user->stripe_id,
            ]);

            $paymentConfirm = Cashier::stripe()->paymentIntents->confirm($paymentIntent->id);

            return response()->json([
                'success' => true,
                'message' => 'Pago realizado correctamente',
                'status' => $paymentConfirm->status
            ]);
        } catch (Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Error al hacer el pago',
                'error_file' => $th->getFile(),
                'error_line' => $th->getLine(),
                'error_message' => $th->getMessage(),
            ]);
        }
    }

    public function chargeInvoice(Request $request, $customerId)
    {
        try {
            $user = User::findOrFail($customerId);
            $user->updateDefaultPaymentMethodFromStripe();
            if (!$user->hasPaymentMethod()) return returnResponse(false, 'No tiene tarjetas registradas');
            else if (!$user->hasDefaultPaymentMethod()) return returnResponse(false, 'No existe tarjeta por defecto');
            // $user->charge(($request->amount * 100), $user->defaultPaymentMethod()->id);
            $invoice = $user->invoiceFor($request->description, ($request->amount * 100));
            return returnResponse(true, 'Pago realizado correctamente', ['invoice' => $invoice]);
        } catch (Throwable $th) {
            return returnResponse(false, 'Error al hacer el pago', [], $th);
        }
    }

    public function updateDefaultCard(Request $request, $customerId)
    {
        try {
            $user = User::findOrFail($customerId);
            $user->updateDefaultPaymentMethodFromStripe();

            if (!$user->hasPaymentMethod()) return returnResponse(false, 'No tiene tarjetas registradas');
            else if (!$user->hasDefaultPaymentMethod()) return returnResponse(false, 'No existe tarjeta por defecto');

            $paymentMethodUpdated = Cashier::stripe()->paymentMethods->update(
                $user->defaultPaymentMethod()->id,
                $request->all()
            );

            return returnResponse(true, 'Tarjeta actualizada correctamente', ['billing_details' => $paymentMethodUpdated]);
        } catch (Throwable $th) {

            return returnResponse(false, 'Error al hacer el pago', [], $th);
        }
    }

    public function changeDefaultCard($customerId)
    {
        try {
            $user = User::findOrFail($customerId);
            $user->updateDefaultPaymentMethodFromStripe();

            if (!$user->hasPaymentMethod()) return returnResponse(false, 'No tiene tarjetas registradas');
            else if (!$user->hasDefaultPaymentMethod()) return returnResponse(false, 'No existe tarjeta por defecto');

            $paymentMethods = $user->paymentMethods();

            foreach ($paymentMethods as $paymentMethod) {
                if ($paymentMethod->id != $user->defaultPaymentMethod()->id) {
                    $user->updateDefaultPaymentMethod($paymentMethod->id);
                    $user->updateDefaultPaymentMethodFromStripe();
                    return returnResponse(true, 'Tarjeta actualizada correctamente', ['default_payment_method' => $user->defaultPaymentMethod()]);
                }
            }
        } catch (Throwable $th) {

            return returnResponse(false, 'Error al hacer el pago', [], $th);
        }
    }

    public function changeNewDefaultCard(Request $request, $customerId)
    {
        try {
            $user = User::findOrFail($customerId);
            $user->updateDefaultPaymentMethodFromStripe();

            $user->updateDefaultPaymentMethod(Cashier::stripe()
                ->paymentMethods
                ->create($request->all())->id);
            $user->updateDefaultPaymentMethodFromStripe();

            return returnResponse(true, 'Tarjeta agregada y cambiada correctamente', ['default_payment_method' => $user->defaultPaymentMethod()]);
        } catch (Throwable $th) {

            return returnResponse(false, 'Error al hacer el pago', [], $th);
        }
    }
}
