public class CriticalInstitution extends DemandArea {

    public CriticalInstitution(
            String areaId,
            String name,
            int dailyDemand,
            String priority) {

        super(areaId, name, dailyDemand, priority);
    }

    @Override
    public int allocationPriority() {
        return 3;
    }
}