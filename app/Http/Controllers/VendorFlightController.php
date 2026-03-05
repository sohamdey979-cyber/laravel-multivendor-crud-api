<?php

namespace App\Http\Controllers\Api\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Flight;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class VendorFlightController extends Controller
{
    /**
     * Display a listing of flights for the authenticated vendor.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Assuming the authenticated user is the vendor or represents the vendor
            $vendorId = Auth::id(); 

            // Example of using Eloquent relationships and eager loading, along with filtering
            $flights = Flight::with(['airline', 'departureAirport', 'arrivalAirport'])
                ->where('vendor_id', $vendorId)
                ->when($request->query('status'), function ($query, $status) {
                    return $query->where('status', $status);
                })
                ->when($request->query('date'), function ($query, $date) {
                    return $query->whereDate('departure_time', $date);
                })
                ->orderBy('departure_time', 'asc')
                ->paginate((int) $request->query('per_page', 15));

            return response()->json([
                'success' => true,
                'message' => 'Flights retrieved successfully.',
                'data' => $flights
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            Log::error('Failed to fetch vendor flights: ' . $e->getMessage(), [
                'vendor_id' => Auth::id(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while retrieving flights.',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal Server Error'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Store a newly created flight in storage.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        try {
            // Inline validation (In a real app, you might use a dedicated FormRequest class)
            $validatedData = $request->validate([
                'flight_number' => 'required|string|unique:flights,flight_number|max:20',
                'airline_id' => 'required|exists:airlines,id',
                'departure_airport_id' => 'required|exists:airports,id',
                'arrival_airport_id' => 'required|different:departure_airport_id|exists:airports,id',
                'departure_time' => 'required|date|after:now',
                'arrival_time' => 'required|date|after:departure_time',
                'price' => 'required|numeric|min:0',
                'available_seats' => 'required|integer|min:1',
                'status' => 'sometimes|in:scheduled,delayed,cancelled',
            ]);

            DB::beginTransaction();

            $vendorId = Auth::id();

            // Create the flight precisely associated with the authenticated vendor
            $flight = Flight::create(array_merge($validatedData, [
                'vendor_id' => $vendorId,
                'status' => $validatedData['status'] ?? 'scheduled'
            ]));

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Flight created successfully.',
                'data' => $flight->load('airline', 'departureAirport', 'arrivalAirport')
            ], Response::HTTP_CREATED);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors()
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create flight: ' . $e->getMessage(), [
                'vendor_id' => Auth::id(),
                'request' => $request->all()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while creating the flight.',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal Server Error'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Display the specified flight.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        try {
            $flight = Flight::with(['airline', 'departureAirport', 'arrivalAirport'])
                ->findOrFail($id);

            // Strict Role-Based Access Control
            $this->authorizeFlightAccess($flight);

            return response()->json([
                'success' => true,
                'message' => 'Flight details retrieved successfully.',
                'data' => $flight
            ], Response::HTTP_OK);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Flight not found.'
            ], Response::HTTP_NOT_FOUND);
        } catch (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], Response::HTTP_FORBIDDEN);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve flight details: ' . $e->getMessage(), [
                'flight_id' => $id,
                'vendor_id' => Auth::id()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while retrieving flight details.'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update the specified flight in storage.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $flight = Flight::findOrFail($id);

            // Strict Role-Based Access Control
            $this->authorizeFlightAccess($flight);

            $validatedData = $request->validate([
                'flight_number' => 'sometimes|string|max:20|unique:flights,flight_number,' . $flight->id,
                'airline_id' => 'sometimes|exists:airlines,id',
                'departure_airport_id' => 'sometimes|exists:airports,id',
                'arrival_airport_id' => 'sometimes|different:departure_airport_id|exists:airports,id',
                'departure_time' => 'sometimes|date',
                'arrival_time' => 'sometimes|date|after:departure_time',
                'price' => 'sometimes|numeric|min:0',
                'available_seats' => 'sometimes|integer|min:0',
                'status' => 'sometimes|in:scheduled,delayed,cancelled',
            ]);

            DB::beginTransaction();

            $flight->update($validatedData);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Flight updated successfully.',
                'data' => $flight->fresh(['airline', 'departureAirport', 'arrivalAirport'])
            ], Response::HTTP_OK);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors()
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Flight not found.'
            ], Response::HTTP_NOT_FOUND);
        } catch (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], Response::HTTP_FORBIDDEN);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update flight: ' . $e->getMessage(), [
                'flight_id' => $id,
                'vendor_id' => Auth::id(),
                'request' => $request->all()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while updating the flight.'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Remove the specified flight from storage.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $flight = Flight::findOrFail($id);

            // Strict Role-Based Access Control
            $this->authorizeFlightAccess($flight);

            // Optional structural check: Prevent deletion if flight has active bookings
            // Assuming a 'bookings' relationship exists on the Flight model
            if (method_exists($flight, 'bookings') && $flight->bookings()->where('status', 'active')->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete flight as there are active bookings associated with it.'
                ], Response::HTTP_CONFLICT);
            }

            DB::beginTransaction();

            $flight->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Flight deleted successfully.'
            ], Response::HTTP_OK);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Flight not found.'
            ], Response::HTTP_NOT_FOUND);
        } catch (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], Response::HTTP_FORBIDDEN);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete flight: ' . $e->getMessage(), [
                'flight_id' => $id,
                'vendor_id' => Auth::id()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while deleting the flight.'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Helper method to enforce strict Role-Based Access Control.
     * Ensures only the owning vendor can interact with the given flight.
     *
     * @param Flight $flight
     * @return void
     * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
     */
    protected function authorizeFlightAccess(Flight $flight): void
    {
        // For even more advanced RBAC, you would use Laravel Policies/Gates here
        // i.e., $this->authorize('update', $flight);
        if ($flight->vendor_id !== Auth::id()) {
            abort(Response::HTTP_FORBIDDEN, 'Unauthorized action. You do not have permission to manage this flight.');
        }
    }
}
