<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Database;

class PaymentCallbackController extends Controller
{
    private $database;

    public function __construct()
    {
        $firebase = (new Factory)
            ->withServiceAccount(config('firebase.firebase.service_account'))
            ->withDatabaseUri('https://fre-kantin-default-rtdb.firebaseio.com');

        $this->database = $firebase->createDatabase();
    }

    public function handle(Request $request)
    {
        $notification = json_decode($request->getContent(), true);
        
        $orderId = $notification['order_id'];
        $transactionStatus = $notification['transaction_status'];
        $fraudStatus = $notification['fraud_status'];
        
        $payment = Payment::where('payment_gateway_reference_id', $orderId)->first();
        if (!$payment) {
            return response()->json(['message' => 'Payment not found'], 404);
        }

        $signatureKey = hash('sha512', 
            $orderId . 
            $notification['status_code'] . 
            $notification['gross_amount'] . 
            env('MIDTRANS_SERVER_KEY')
        );

        if ($notification['signature_key'] !== $signatureKey) {
            return response()->json(['message' => 'Invalid signature'], 403);
        }

        if ($fraudStatus == 'accept') {
            switch ($transactionStatus) {
                case 'capture':
                case 'settlement':
                    $paymentStatus = 'SUCCESS';
                    $orderStatus = 'PAID';
                    break;
                case 'pending':
                    $paymentStatus = 'PENDING';
                    $orderStatus = 'PENDING';
                    break;
                case 'deny':
                case 'expire':
                case 'cancel':
                    $paymentStatus = 'FAILED'   ;
                    $orderStatus = 'CANCELLED';
                    break;
                default:
                    $paymentStatus = 'FAILED';
                    $orderStatus = 'FAILED';
            }

            $payment->update([
                'payment_status' => $paymentStatus,
                'payment_date' => now(),
            ]);

            $payment->order->update([
                'order_status' => $orderStatus
            ]);
        }

        try {
            // Cari order di Realtime Database berdasarkan order_id
            $ordersRef = $this->database->getReference('notifications/orders');
            $query = $ordersRef->orderByChild('order_id')->equalTo($orderId);
            $snapshot = $query->getSnapshot();
        
            if ($snapshot->exists()) {
                // Iterasi hasil dan update status menjadi 'PAID'
                foreach ($snapshot->getValue() as $key => $orderData) {
                    $ordersRef->getChild($key)->update([
                        'status' => $orderStatus, // Nilai $orderStatus (contoh: 'PAID')
                        'updated_at' => time()   // Timestamp pembaruan
                    ]);
                }
                
            } else {
                Log::warning("Order $orderId not found in Firebase");
            }
        } catch (\Exception $e) {
            Log::error('Error updating Firebase: ' . $e->getMessage());
            return response()->json(['message' => 'Error updating Firebase'], 500);
        }
        

        return response()->json(['message' => 'Payment status updated']);
    }
}