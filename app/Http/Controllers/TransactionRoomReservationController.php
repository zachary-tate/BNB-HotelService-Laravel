<?php

namespace App\Http\Controllers;

use App\Helpers\Helper;
use App\Http\Requests\ChooseRoomRequest;
use App\Http\Requests\StoreCustomerRequest;
use App\Models\Customer;
use App\Models\Room;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;


class TransactionRoomReservationController extends Controller
{
    public function pickFromCustomer(Request $request)
    {
        $customers = Customer::with('user')->orderBy('id', 'DESC');
        $customersCount = Customer::with('user')->orderBy('id', 'DESC');
        if (!empty($request->q)) {
            $customers = $customers->where('name', 'Like', '%' . $request->q . '%')
                ->orWhere('id', 'Like', '%' . $request->q . '%');
            $customersCount = $customersCount->where('name', 'Like', '%' . $request->q . '%')
                ->orWhere('id', 'Like', '%' . $request->q . '%');
        }
        $customers = $customers->paginate(8);
        $customersCount = $customersCount->count();
        $customers->appends($request->all());
        return view('transaction.reservation.pickFromCustomer', compact('customers', 'customersCount'));
    }

    public function createIdentity()
    {
        return view('transaction.reservation.createIdentity');
    }

    public function storeCustomer(StoreCustomerRequest $request)
    {
        $request->validate([
            'email' => 'required|unique:users,email',
            'avatar' => 'mimes:png,jpg',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => bcrypt($request->birthdate),
            'role' => 'Customer'
        ]);

        if ($request->hasFile('avatar')) {
            $path = 'img/user/' . $user->name . '-' . $user->id;
            $path = public_path($path);
            $file = $request->file('avatar');

            Helper::uploadImage($path, $file);

            $user->avatar = $file->getClientOriginalName();
            $user->save();
        }

        $customer = Customer::create([
            'name' => $user->name,
            'address' => $request->address,
            'job' => $request->job,
            'birthdate' => $request->birthdate,
            'gender' => $request->gender,
            'user_id' => $user->id
        ]);

        return redirect()->route('transaction.reservation.countPerson', ['customer' => $customer->id])->with('success', 'Customer ' . $customer->name . ' created!');
    }

    public function countPerson(Customer $customer)
    {
        return view('transaction.reservation.countPerson', compact('customer'));
    }

    public function chooseRoom(Customer $customer, ChooseRoomRequest $request)
    {
        $stayFrom = $request->check_in;
        $stayUntil = $request->check_out;

        $occupiedRoomId = $this->getOccupiedRoomID($stayFrom, $stayUntil);

        $rooms = Room::with('type', 'roomStatus')
            ->where('capacity', '>=', $request->count_person)
            ->whereNotIn('id', $occupiedRoomId)
            ->orderBy('capacity')
            ->orderBy('price')
            ->get();

        return view('transaction.reservation.chooseRoom', compact('customer', 'rooms', 'stayFrom', 'stayUntil'));
    }

    public function confirmation(Customer $customer, Room $room, $stayFrom, $stayUntil)
    {
        $price = $room->price;
        $dayDifference = Helper::getDateDifference($stayFrom, $stayUntil);
        $downPayment = ($price * $dayDifference) * 0.15;
        return view('transaction.reservation.confirmation', compact('customer', 'room', 'stayFrom', 'stayUntil', 'downPayment', 'dayDifference'));
    }

    public function payDownPayment(Customer $customer, Room $room, Request $request)
    {
        $stayFrom = $request->check_in;
        $stayUntil = $request->check_out;

        $occupiedRoomId = $this->getOccupiedRoomID($stayFrom, $stayUntil);
        $occupiedRoomIdInArray = $occupiedRoomId->toArray();

        if (in_array($room->id, $occupiedRoomIdInArray)) {
            return redirect()->back()->with('failed', 'Sorry, room ' . $room->number . ' already occupied');
        }

        Transaction::create([
            'user_id' => auth()->user()->id,
            'customer_id' => $customer->id,
            'room_id' => $room->id,
            'check_in' => $request->check_in,
            'check_out' => $request->check_out,
            'status' => 'Reservation'
        ]);

        return redirect()->route('transaction.index')->with('success', 'Room ' . $room->number . ' has been reservated by ' . $customer->name);
    }

    private function getOccupiedRoomID($stayFrom, $stayUntil)
    {
        $occupiedRoomId = Transaction::where([['check_in', '<=', $stayFrom], ['check_out', '>=', $stayUntil]])
            ->orWhere([['check_in', '>=', $stayFrom], ['check_in', '<=', $stayUntil]])
            ->orWhere([['check_out', '>=', $stayFrom], ['check_out', '<=', $stayUntil]])
            ->pluck('room_id');
        return $occupiedRoomId;
    }
}
