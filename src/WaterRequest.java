public class WaterRequest {

    private final DemandArea area;
    private final int requestedLitres;
    private final int arrivalOrder;

    public WaterRequest(
            DemandArea area,
            int requestedLitres,
            int arrivalOrder) {

        this.area = area;
        this.requestedLitres = requestedLitres;
        this.arrivalOrder = arrivalOrder;
    }

    public DemandArea getArea() {
        return area;
    }

    public int getRequestedLitres() {
        return requestedLitres;
    }

    public int getArrivalOrder() {
        return arrivalOrder;
    }

    @Override
    public String toString() {
        return area.getName()
                + " requested "
                + requestedLitres
                + " L (order #"
                + arrivalOrder
                + ")";
    }
}